<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Export;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Config;
use SineMacula\Exporter\Export\Concerns\BuildsQueryConstraints;
use SineMacula\Exporter\Jobs\ExportToDiskJob;
use SineMacula\Exporter\Testing\ExporterFake;

/**
 * Fluent queued-export builder.
 *
 * The serializable counterpart to ResourceExport: where the streamed helper
 * wraps a live query builder, this builder accumulates a model and resource
 * class plus a list of plain constraint descriptors (where / whereIn /
 * whereNull / orderBy / limit, or a named scope) that can be replayed on a
 * queue worker. Its terminal verb, queue(), assembles an ExportSpecification
 * and dispatches the ExportToDiskJob, returning the framework's PendingDispatch
 * so the connection and queue can be chosen with the usual onConnection() /
 * onQueue() verbs.
 *
 * A live builder or closure is deliberately not accepted: neither serializes
 * reliably onto a queue. Express the selection as constraints instead, and the
 * worker rebuilds the query from the model.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class QueuedExport
{
    use BuildsQueryConstraints;

    /** @var class-string<\SineMacula\Exporter\Schema\TabularSchema>|null The dedicated schema class */
    private ?string $schema = null;

    /** @var string The negotiated export format name */
    private string $format;

    /** @var string|null The target storage disk */
    private ?string $disk = null;

    /** @var string|null The destination path on the disk */
    private ?string $path = null;

    /** @var string|null The download filename hint */
    private ?string $filename = null;

    /** @var int|string|null The initiating actor identifier */
    private int|string|null $actorId = null;

    /** @var class-string<\Illuminate\Database\Eloquent\Model>|null The actor's model class */
    private ?string $actorClass = null;

    /** @var string|null The gate ability re-checked against the full set */
    private ?string $ability = null;

    /** @var bool Whether the explicit full-set authorization requirement is waived */
    private bool $withoutAuthorization = false;

    /** @var int The keyset chunk size used while streaming */
    private int $chunkSize = 1000;

    /** @var int The number of rows between progress events */
    private int $progressEvery = 1000;

    /** @var int The signed URL lifetime in minutes */
    private int $urlExpiresAfter = 60;

    /**
     * Create a new queued-export builder.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resource
     */
    private function __construct(

        /** @var class-string<\Illuminate\Database\Eloquent\Model> The model the export query runs over */
        private readonly string $model,

        /** @var class-string<\Illuminate\Http\Resources\Json\JsonResource> The resource class describing the export */
        private readonly string $resource,
    ) {
        $format       = Config::get('exporter.default', 'csv');
        $this->format = is_string($format) ? $format : 'csv';
    }

    /**
     * Begin a queued export for the given model and resource class.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resource
     * @return self
     */
    public static function forModel(string $model, string $resource): self
    {
        return new self($model, $resource);
    }

    /**
     * Set the dedicated schema class describing the tabular shape.
     *
     * @param  class-string<\SineMacula\Exporter\Schema\TabularSchema>  $schema
     * @return $this
     */
    public function schema(string $schema): static
    {
        $this->schema = $schema;

        return $this;
    }

    /**
     * Set the export format.
     *
     * @param  string  $format
     * @return $this
     */
    public function format(string $format): static
    {
        $this->format = $format;

        return $this;
    }

    /**
     * Set the target storage disk and destination path.
     *
     * @param  string  $disk
     * @param  string  $path
     * @return $this
     */
    public function toDisk(string $disk, string $path): static
    {
        $this->disk = $disk;
        $this->path = $path;

        return $this;
    }

    /**
     * Set the download filename hint, without extension.
     *
     * @param  string  $filename
     * @return $this
     */
    public function as(string $filename): static
    {
        $this->filename = $filename;

        return $this;
    }

    /**
     * Attribute the export to the given actor for audit and re-authorization.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user
     * @return $this
     */
    public function by(Authenticatable $user): static
    {
        $id = $user->getAuthIdentifier();

        $this->actorId    = is_int($id) || is_string($id) ? $id : null;
        $this->actorClass = $user instanceof Model ? $user::class : null;

        return $this;
    }

    /**
     * Re-check the given gate ability against the full set on the worker.
     *
     * @param  string  $ability
     * @return $this
     */
    public function authorize(string $ability): static
    {
        $this->ability = $ability;

        return $this;
    }

    /**
     * Waive the explicit full-set authorization requirement for this export.
     *
     * A conscious opt-out, not a default: use it only when access to the full
     * set is already enforced upstream. Dispatching without either this or
     * authorize() throws.
     *
     * @return $this
     */
    public function withoutAuthorization(): static
    {
        $this->withoutAuthorization = true;

        return $this;
    }

    /**
     * Set the keyset chunk size used while streaming the full set.
     *
     * @param  int  $size
     * @return $this
     */
    public function chunk(int $size): static
    {
        $this->chunkSize = $size;

        return $this;
    }

    /**
     * Set the number of rows between progress events.
     *
     * @param  int  $rows
     * @return $this
     */
    public function progressEvery(int $rows): static
    {
        $this->progressEvery = $rows;

        return $this;
    }

    /**
     * Set the signed temporary URL lifetime, in minutes.
     *
     * @param  int  $minutes
     * @return $this
     */
    public function expireAfter(int $minutes): static
    {
        $this->urlExpiresAfter = $minutes;

        return $this;
    }

    /**
     * Build the serializable specification for the queued export.
     *
     * @return \SineMacula\Exporter\Export\ExportSpecification
     *
     * @throws \LogicException
     */
    public function toSpecification(): ExportSpecification
    {
        if ($this->disk === null || $this->path === null) {
            throw new \LogicException('A queued export requires a target disk and path; call toDisk().');
        }

        return new ExportSpecification(
            $this->model,
            $this->resource,
            $this->schema,
            $this->format,
            $this->disk,
            $this->path,
            $this->constraints,
            $this->filename,
            $this->actorId,
            $this->actorClass,
            $this->ability,
            $this->chunkSize,
            $this->progressEvery,
            $this->urlExpiresAfter,
        );
    }

    /**
     * Dispatch the queued export job for the assembled specification.
     *
     * Requires an explicit full-set authorization decision first - a gate
     * ability via authorize(), or a conscious withoutAuthorization() opt-out -
     * throwing a LogicException otherwise. While Exporter::fake() is active the
     * specification is recorded instead of dispatched for real; the bus fake
     * the double installs captures the job so it is never run, and the
     * recording feeds the queued-export assertions.
     *
     * @return \Illuminate\Foundation\Bus\PendingDispatch
     *
     * @throws \LogicException
     */
    public function queue(): PendingDispatch
    {
        if ($this->ability === null && !$this->withoutAuthorization) {
            throw new \LogicException('A queued export must register a full-set authorization ability with authorize(), or explicitly opt out with withoutAuthorization().');
        }

        $specification = $this->toSpecification();

        ExporterFake::active()?->recordQueue($specification);

        return ExportToDiskJob::dispatch($specification);
    }
}
