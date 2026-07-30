<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use SineMacula\Exporter\Contracts\ProvidesTabularExport;
use SineMacula\Exporter\Contracts\Writer;
use SineMacula\Exporter\Engine;
use SineMacula\Exporter\Events\ExportCompleted;
use SineMacula\Exporter\Events\ExportFailed;
use SineMacula\Exporter\Events\ExportStarting;
use SineMacula\Exporter\Events\RowsExported;
use SineMacula\Exporter\Exceptions\NoTabularRepresentation;
use SineMacula\Exporter\Exceptions\SinkException;
use SineMacula\Exporter\Export\ExportAuditor;
use SineMacula\Exporter\Export\ExportSpecification;
use SineMacula\Exporter\Http\MediaTypeRegistry;
use SineMacula\Exporter\Schema\TabularSchema;
use SineMacula\Exporter\Schema\WarningCollector;
use SineMacula\Exporter\Sinks\DiskSink;
use SineMacula\Exporter\Sinks\StreamSink;
use SineMacula\Exporter\Sources\QueryChunkSource;
use SineMacula\Exporter\Writers\CountingWriter;

/**
 * Queued export-to-disk job.
 *
 * Streams a full dataset to a storage disk on a queue worker at constant
 * memory. It rebuilds the query from the serializable specification (model plus
 * constraint descriptors), re-checks full-set authorization, then streams the
 * keyset-chunked query through the schema and writer into a local staging file
 * before handing the finished file to the disk - so a non-seekable disk and a
 * finalise-on-close format (XLSX) are both served without buffering the set in
 * memory. Once stored, it resolves a signed temporary download URL where the
 * disk supports one.
 *
 * Lifecycle events are fired throughout: ExportStarting up front, RowsExported
 * periodically as rows stream, ExportCompleted (the shared audit event) on
 * success, and ExportFailed once the job exhausts its retries. The job is
 * retry-safe: every attempt stages to its own temp file, cleans that file up,
 * and removes a partially written disk file only when this attempt created the
 * destination, so a retry starts from a clean slate without deleting a
 * pre-existing file at the same path. Once storage succeeds, completion
 * listener failures are logged but do not roll back the stored export.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ExportToDiskJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** @var int The number of times the job may be attempted */
    public readonly int $tries;

    /** @var int The number of seconds the job may run before timing out */
    public readonly int $timeout;

    /**
     * Create a new queued export job.
     *
     * @param  \SineMacula\Exporter\Export\ExportSpecification  $spec
     */
    public function __construct(

        /** The serializable specification describing the export. */
        private readonly ExportSpecification $spec,
    ) {
        $this->tries   = 3;
        $this->timeout = 600;
    }

    /**
     * Run the export, streaming the full dataset to the configured disk.
     *
     * @param  \SineMacula\Exporter\Engine  $engine
     * @param  \SineMacula\Exporter\Export\ExportAuditor  $auditor
     * @param  \SineMacula\Exporter\Http\MediaTypeRegistry  $registry
     * @param  \Illuminate\Contracts\Filesystem\Factory  $filesystem
     * @return void
     *
     * @throws \SineMacula\Exporter\Exceptions\NoTabularRepresentation
     * @throws \Throwable
     */
    public function handle(Engine $engine, ExportAuditor $auditor, MediaTypeRegistry $registry, Factory $filesystem): void
    {
        $this->guardAuthorizationDecision();

        Event::dispatch(new ExportStarting($this->spec->format, $this->spec->disk, $this->spec->path, $this->spec->actorId));

        $disk             = $filesystem->disk($this->spec->disk);
        $targetExisted    = $this->hasExistingTarget($disk);
        $storageAttempted = false;
        $staging          = null;

        try {
            $actor   = $this->actor();
            $request = $this->request($actor);
            $query   = $this->spec->query();

            $auditor->authorize(actor: $actor, ability: $this->spec->ability, arguments: $this->spec->model);

            $writer = $registry->writerFor($this->spec->format);

            if ($writer === null) {
                throw NoTabularRepresentation::forResource($this->spec->format);
            }

            $schema  = $this->schema($request);
            $staging = $this->stagingPath();
            $rows    = $this->stream($engine, $query, $schema, $writer, $request, $staging);

            $storageAttempted = true;

            (new DiskSink($disk, $this->spec->path))->putFromFile($staging);

            $this->reportCompleted($auditor, new ExportCompleted(
                $this->spec->actorId,
                $rows,
                $this->spec->filename,
                $this->spec->format,
                CarbonImmutable::now(),
                $this->spec->disk,
                $this->spec->path,
                $this->signedUrl($disk),
                queued: true,
            ));
        } catch (\Throwable $exception) {
            $this->cleanupFailedStorageAttempt($disk, $targetExisted, $storageAttempted);

            throw $exception;
        } finally {
            if ($staging !== null) {
                @unlink($staging);
            }
        }
    }

    /**
     * Fire the failure event once the job has exhausted its retries.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Event::dispatch(new ExportFailed(
            $this->spec->format,
            $this->spec->disk,
            $this->spec->path,
            $this->spec->actorId,
            $exception,
        ));
    }

    /**
     * Dispatch the completion audit without rolling back a stored export when a
     * listener fails.
     *
     * @param  \SineMacula\Exporter\Export\ExportAuditor  $auditor
     * @param  \SineMacula\Exporter\Events\ExportCompleted  $event
     * @return void
     */
    private function reportCompleted(ExportAuditor $auditor, ExportCompleted $event): void
    {
        try {
            $auditor->completed($event);
        } catch (\Throwable $exception) { // @phpstan-ignore catch.neverThrown
            Log::warning('Queued export completed, but completion handling failed.', [
                'disk'      => $this->spec->disk,
                'path'      => $this->spec->path,
                'format'    => $this->spec->format,
                'actor_id'  => $this->spec->actorId,
                'exception' => $exception,
            ]);
        }
    }

    /**
     * Require the serialized export to carry an explicit full-set authorization
     * decision.
     *
     * @return void
     *
     * @throws \LogicException
     */
    private function guardAuthorizationDecision(): void
    {
        if ($this->spec->ability !== null || $this->spec->authorizationWaived) {
            return;
        }

        throw new \LogicException('A queued export specification must carry a full-set authorization ability, or explicitly waive authorization.');
    }

    /**
     * Determine whether the destination path existed before this attempt.
     *
     * An unreadable destination is reported as pre-existing, so a probe that
     * cannot answer never authorises the cleanup delete.
     *
     * @param  \Illuminate\Contracts\Filesystem\Filesystem  $disk
     * @return bool
     */
    private function hasExistingTarget(Filesystem $disk): bool
    {
        try {
            return $disk->exists($this->spec->path);
        } catch (\Throwable) { // @phpstan-ignore catch.neverThrown
            return true;
        }
    }

    /**
     * Remove a partial file from this attempt without deleting a pre-existing
     * destination.
     *
     * @param  \Illuminate\Contracts\Filesystem\Filesystem  $disk
     * @param  bool  $targetExisted
     * @param  bool  $storageAttempted
     * @return void
     */
    private function cleanupFailedStorageAttempt(Filesystem $disk, bool $targetExisted, bool $storageAttempted): void
    {
        if (!$storageAttempted || $targetExisted) {
            return;
        }

        try {
            $disk->delete($this->spec->path);
        } catch (\Throwable $exception) { // @phpstan-ignore catch.neverThrown
            Log::warning('Unable to remove a failed queued export file.', [
                'disk'      => $this->spec->disk,
                'path'      => $this->spec->path,
                'exception' => $exception,
            ]);
        }
    }

    /**
     * Stream the query through the schema and writer into the staging file.
     *
     * The rows are counted at the writer boundary so progress can be reported
     * periodically and the final count carried into the audit event, all
     * without buffering the set.
     *
     * @param  \SineMacula\Exporter\Engine  $engine
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  \SineMacula\Exporter\Schema\TabularSchema  $schema
     * @param  \SineMacula\Exporter\Contracts\Writer  $writer
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $staging
     * @return int
     *
     * @throws \SineMacula\Exporter\Exceptions\SinkException
     */
    private function stream(Engine $engine, Builder $query, TabularSchema $schema, Writer $writer, Request $request, string $staging): int
    {
        $handle = fopen($staging, 'w+b');

        // The staging path was just allocated by tempnam(), so it is always
        // present and writable; this guard cannot be exercised without
        // injecting a filesystem fault.
        // @codeCoverageIgnoreStart
        if ($handle === false) {
            throw new SinkException("Unable to open staging file [{$staging}] for the queued export.");
        }
        // @codeCoverageIgnoreEnd
        $rows     = 0;
        $warnings = new WarningCollector;

        $counting = new CountingWriter($writer, function (int $count) use (&$rows): void {
            $rows = $count;

            $this->reportProgress($count);
        });

        try {
            $engine->export(new QueryChunkSource($query, $this->spec->chunkSize), $schema, $request, $counting, new StreamSink($handle), $warnings);

            fflush($handle);
        } finally {
            fclose($handle);
        }

        $this->logWarnings($warnings);

        return $rows;
    }

    /**
     * Log any warnings a lenient export collected once it completes cleanly.
     *
     * Lenient strictness degrades a bad column or cell rather than failing, so
     * the export still succeeds; surfacing the collected warnings here gives an
     * operator visibility of the silent degradation in the stored file.
     *
     * @param  \SineMacula\Exporter\Schema\WarningCollector  $warnings
     * @return void
     */
    private function logWarnings(WarningCollector $warnings): void
    {
        if ($warnings->isEmpty()) {
            return;
        }

        Log::warning('Resource export completed with warnings.', [
            'format'   => $this->spec->format,
            'warnings' => $warnings->all(),
        ]);
    }

    /**
     * Fire a progress event every configured number of rows.
     *
     * @param  int  $count
     * @return void
     */
    private function reportProgress(int $count): void
    {
        if ($this->spec->progressEvery <= 0 || $count % $this->spec->progressEvery !== 0) {
            return;
        }

        Event::dispatch(new RowsExported($count, $this->spec->format, $this->spec->disk, $this->spec->path));
    }

    /**
     * Resolve the tabular schema for the export, or throw a 406.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \SineMacula\Exporter\Schema\TabularSchema
     *
     * @throws \SineMacula\Exporter\Exceptions\NoTabularRepresentation
     */
    private function schema(Request $request): TabularSchema
    {
        if ($this->spec->schema !== null) {
            return new $this->spec->schema($request);
        }

        $resource = $this->spec->resource;

        if (!is_a($resource, ProvidesTabularExport::class, true)) {
            throw NoTabularRepresentation::forResource($resource);
        }

        /** @var \Illuminate\Http\Resources\Json\JsonResource&\SineMacula\Exporter\Contracts\ProvidesTabularExport $probe */
        $probe = new $resource(null);

        return $probe->tabular($request);
    }

    /**
     * Resolve the initiating actor from the specification, if any.
     *
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    private function actor(): ?Authenticatable
    {
        if ($this->spec->actorClass === null || $this->spec->actorId === null) {
            return null;
        }

        $model = $this->spec->actorClass;
        $actor = $model::query()->find($this->spec->actorId);

        return $actor instanceof Authenticatable ? $actor : null;
    }

    /**
     * Build a request carrying the initiating actor for the schema and gate.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable|null  $actor
     * @return \Illuminate\Http\Request
     */
    private function request(?Authenticatable $actor): Request
    {
        $request = Request::create('/');

        if ($actor !== null) {
            $request->setUserResolver(static fn (): Authenticatable => $actor);
        }

        return $request;
    }

    /**
     * Allocate the local staging file the export is written to.
     *
     * @return string
     *
     * @throws \SineMacula\Exporter\Exceptions\SinkException
     */
    private function stagingPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'export_queue_');

        // tempnam() falls back to the system temp directory rather than failing
        // on a bad directory, so this guard cannot be exercised without
        // injecting a filesystem fault.
        // @codeCoverageIgnoreStart
        if ($path === false) {
            throw new SinkException('Unable to allocate a staging file for the queued export.');
        }
        // @codeCoverageIgnoreEnd
        return $path;
    }

    /**
     * Resolve a signed temporary URL for the stored file, where supported.
     *
     * @param  \Illuminate\Contracts\Filesystem\Filesystem  $disk
     * @return string|null
     */
    private function signedUrl(Filesystem $disk): ?string
    {
        if (!method_exists($disk, 'temporaryUrl')) {
            return null;
        }

        try {
            return $disk->temporaryUrl($this->spec->path, CarbonImmutable::now()->addMinutes($this->spec->urlExpiresAfter));
        } catch (\Throwable $exception) {
            Log::warning('Unable to generate a signed temporary URL for the stored export.', [
                'disk'      => $this->spec->disk,
                'path'      => $this->spec->path,
                'exception' => $exception,
            ]);

            return null;
        }
    }
}
