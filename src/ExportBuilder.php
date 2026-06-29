<?php

declare(strict_types = 1);

namespace SineMacula\Exporter;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use SineMacula\Exporter\Contracts\ProvidesTabularExport;
use SineMacula\Exporter\Contracts\Sink;
use SineMacula\Exporter\Events\StreamExportFailed;
use SineMacula\Exporter\Exceptions\NoTabularRepresentation;
use SineMacula\Exporter\Exceptions\SinkException;
use SineMacula\Exporter\Export\ExportFilename;
use SineMacula\Exporter\Export\HierarchicalRows;
use SineMacula\Exporter\Http\MediaTypeRegistry;
use SineMacula\Exporter\Schema\TabularSchema;
use SineMacula\Exporter\Schema\WarningCollector;
use SineMacula\Exporter\Sinks\DiskSink;
use SineMacula\Exporter\Sinks\StreamedResponseSink;
use SineMacula\Exporter\Sinks\StreamSink;
use SineMacula\Exporter\Sinks\StringSink;
use SineMacula\Exporter\Sources\ConnectionAwareSource;
use SineMacula\Exporter\Sources\QueryChunkSource;
use SineMacula\Exporter\Sources\SourceFactory;
use SineMacula\Exporter\Testing\ExporterFake;
use SineMacula\Exporter\Writers\CountingWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fluent explicit-export builder.
 *
 * The single, top-level entry point for an explicit (non-negotiated) export. It
 * composes the existing pieces rather than re-implementing them: a subject (a
 * resource item, a resource collection, or an Eloquent query) is normalised
 * into a Source adapter, a format selects a Writer from the media registry, and
 * a tabular schema (declared explicitly, or resolved from the resource) drives
 * the Engine; the shaped rows stream into whichever Sink the chosen verb wants.
 *
 * The verbs are the community vocabulary: download() and toResponse() return a
 * streamed attachment response, store() writes to a Storage disk, toString()
 * buffers to a string, and toStream() streams into a resource. A full-set
 * queued export goes through the dedicated serializable door,
 * Exporter::queue($model, $resource), whose constraint descriptors round-trip
 * to the worker. While Exporter::fake() is active every verb records what it
 * would have exported instead of producing bytes. The builder is
 * request-explicit and holds no shared state.
 *
 * Two behaviours are by design. CSV/TSV stream at constant memory, but XLSX
 * finalises on close and so buffers the whole workbook before the response
 * flushes - a large XLSX served synchronously through download()/toResponse()
 * holds the set in memory, so queue it (Exporter::queue()) when the set can be
 * large. And an empty result set produces a header-less file (no rows means no
 * header is written) rather than a lone column header.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1448")
 */
final class ExportBuilder // phpcs:ignore SineMacula.Metrics.MaxMethodCount.TooManyMethods
{
    /** @var string The negotiated export format name */
    private string $format;

    /** @var class-string<\SineMacula\Exporter\Schema\TabularSchema>|\SineMacula\Exporter\Schema\TabularSchema|null The explicit schema, instance or class */
    private string|TabularSchema|null $schema = null;

    /** @var string|null The download filename hint, without extension */
    private ?string $filename = null;

    /** @var \Illuminate\Http\Request|null The request the schema and resources are built for */
    private ?Request $request = null;

    /** @var int The keyset chunk size used while streaming a query */
    private int $chunkSize = 1000;

    /** @var list<string> The extra eager-load relations applied to a query subject */
    private array $with = [];

    /**
     * Create a new explicit-export builder.
     *
     * Construct it through Exporter::export()/collection()/query() rather than
     * directly: those resolve the shared, config-seeded media-type registry and
     * engine, whereas a direct construction falls back to empty defaults that
     * see no custom formats or casters.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>|\Illuminate\Http\Resources\Json\JsonResource  $subject
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>|null  $resourceClass
     * @param  \SineMacula\Exporter\Http\MediaTypeRegistry  $registry
     * @param  \SineMacula\Exporter\Engine  $engine
     * @param  (\Closure(): bool)|null  $abortSignal
     *
     * @internal
     */
    public function __construct(

        /** @var \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>|\Illuminate\Http\Resources\Json\JsonResource The export subject */
        private readonly Builder|JsonResource $subject,

        /** @var class-string<\Illuminate\Http\Resources\Json\JsonResource>|null The resource class describing a query subject */
        private readonly ?string $resourceClass = null,

        /** The media registry resolving writers and formats. */
        private readonly MediaTypeRegistry $registry = new MediaTypeRegistry,

        /** The export engine streaming rows into a writer. */
        private readonly Engine $engine = new Engine,

        /** @var (\Closure(): bool)|null The client-disconnect signal, or null for connection_aborted() */
        private readonly ?\Closure $abortSignal = null,
    ) {
        $default      = Config::get('exporter.default', 'csv');
        $this->format = is_string($default) ? $default : 'csv';
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
     * Set the tabular schema describing the export, as an instance or class.
     *
     * @param  class-string<\SineMacula\Exporter\Schema\TabularSchema>|\SineMacula\Exporter\Schema\TabularSchema  $schema
     * @return $this
     */
    public function schema(string|TabularSchema $schema): static
    {
        $this->schema = $schema;

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
     * Set the request the schema and resources are built for.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return $this
     */
    public function request(Request $request): static
    {
        $this->request = $request;

        return $this;
    }

    /**
     * Set the keyset chunk size used while streaming a query.
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
     * Add eager-load relations applied to a query subject before streaming.
     *
     * Mirrors the relations a tabular schema derives automatically: the
     * hierarchical (JSON, NDJSON, XML) formats have no schema to derive from,
     * so declare here the relations the resource's toArray() touches to avoid
     * an N+1 across the streamed set. Accepts a single relation or a list, and
     * merges across calls.
     *
     * @param  list<string>|string  $relations
     * @return $this
     */
    public function with(array|string $relations): static
    {
        $this->with = array_values(array_unique([...$this->with, ...(array) $relations]));

        return $this;
    }

    /**
     * Buffer the export to a string and return it.
     *
     * @return string
     */
    public function toString(): string
    {
        $fake = ExporterFake::active();

        if ($fake !== null) {
            $fake->recordString($this->format, $this->countRows());

            return '';
        }

        $sink = new StringSink;

        $this->writeInto($sink);

        return $sink->contents();
    }

    /**
     * Stream the export into the given writable stream resource.
     *
     * @param  resource  $stream
     * @return int
     */
    public function toStream($stream): int // phpcs:ignore SineMaculaLaravel.TypeHints.ParameterTypeHint.MissingNativeTypeHint
    {
        $fake = ExporterFake::active();

        if ($fake !== null) {
            $rows = $this->countRows();

            $fake->recordStream($this->format, $rows);

            return $rows;
        }

        return $this->writeInto(new StreamSink($stream));
    }

    /**
     * Write the export to a storage disk and return the destination path.
     *
     * The export is staged to a local seekable file and then handed to the
     * disk, so a streaming writer and a non-seekable disk are both served at
     * constant memory - the same path the queued pipeline takes.
     *
     * @param  string  $disk
     * @param  string  $path
     * @return string
     *
     * @throws \SineMacula\Exporter\Exceptions\SinkException
     */
    public function store(string $disk, string $path): string
    {
        $fake = ExporterFake::active();

        if ($fake !== null) {
            $fake->recordStored($disk, $path, $this->format, $this->countRows());

            return $path;
        }

        $filesystem = Container::getInstance()->make(Factory::class)->disk($disk);
        $staging    = $this->stage();

        try {
            (new DiskSink($filesystem, $path))->putFromFile($staging);
        } finally {
            @unlink($staging);
        }

        return $path;
    }

    /**
     * Build a streamed download response with an attachment disposition.
     *
     * @param  string|null  $filename
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function download(?string $filename = null): StreamedResponse
    {
        return $this->streamedResponse($filename ?? $this->filenameHint());
    }

    /**
     * Build a streamed export response, optionally for the given request.
     *
     * @param  \Illuminate\Http\Request|null  $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function toResponse(?Request $request = null): StreamedResponse
    {
        if ($request !== null) {
            $this->request = $request;
        }

        return $this->streamedResponse($this->filenameHint());
    }

    /**
     * Build a streamed attachment response for the given filename.
     *
     * @param  string  $filename
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    private function streamedResponse(string $filename): StreamedResponse
    {
        $source = SourceFactory::for($this->subject, $this->chunkSize);

        if ($source instanceof QueryChunkSource) {
            $source->guardKeysetOrdering();
        }

        $names   = new ExportFilename($this->registry, $this->format);
        $headers = [
            'Content-Type'        => $names->mediaType() . '; charset=UTF-8',
            'Content-Disposition' => $names->disposition($filename),
            'Vary'                => 'Accept',
        ];

        $fake = ExporterFake::active();

        if ($fake !== null) {
            $fake->recordDownload($filename, $names->resolve($filename), $this->format, $this->countRows());

            return new StreamedResponse(static fn (): null => null, 200, $headers);
        }

        $this->preflight();

        $rows = 0;

        return (new StreamedResponseSink)->toResponse(
            function (Sink $sink) use (&$rows): void {
                try {
                    $this->writeInto($sink, static function (int $count) use (&$rows): void {
                        $rows = $count;
                    }, true);
                } catch (\Throwable $exception) {
                    $this->failStream($this->format, $rows, $exception);
                }
            },
            200,
            $headers,
        );
    }

    /**
     * Handle a mid-stream failure after the status and bytes are committed.
     *
     * Mirrors the negotiated path: the writer has already flushed its
     * documented truncation marker, so the stream stops cleanly here and the
     * failure is surfaced through a StreamExportFailed event carrying the row
     * context and logged. It is never re-thrown, because the 200 response can
     * no longer become an error.
     *
     * @param  string  $format
     * @param  int  $rows
     * @param  \Throwable  $exception
     * @return void
     */
    private function failStream(string $format, int $rows, \Throwable $exception): void
    {
        $user    = $this->currentRequest()->user();
        $id      = $user instanceof Authenticatable ? $user->getAuthIdentifier() : null;
        $actorId = is_int($id) || is_string($id) ? $id : null;

        Event::dispatch(new StreamExportFailed($format, $rows, $actorId, $exception));

        Log::warning('Resource export stream truncated after the response had begun.', [
            'format'       => $format,
            'rows_written' => $rows,
            'exception'    => $exception,
        ]);
    }

    /**
     * Validate a tabular schema before a streamed response commits its 200.
     *
     * The writer and media type are already resolved (and a missing one
     * already thrown) while building the response headers; this adds the
     * schema check so a preflight-strict schema fault throws here - before any
     * bytes are streamed, rather than mid-body into an already-committed 200.
     *
     * @return void
     *
     * @throws \SineMacula\Exporter\Exceptions\InvalidExportSchema
     */
    private function preflight(): void
    {
        if (!$this->registry->isTabular($this->format)) {
            return;
        }

        $this->engine->preflight($this->resolveSchema(), $this->currentRequest());
    }

    /**
     * Stage the export to a local seekable file and return its path.
     *
     * @return string
     *
     * @throws \SineMacula\Exporter\Exceptions\SinkException
     */
    private function stage(): string
    {
        $staging = tempnam(sys_get_temp_dir(), 'export_store_');

        // tempnam() falls back to the system temp directory rather than failing
        // on a bad directory, so this guard cannot be exercised without
        // injecting a filesystem fault.
        // @codeCoverageIgnoreStart
        if ($staging === false) {
            throw new SinkException('Unable to allocate a staging file for the export.');
        }
        // @codeCoverageIgnoreEnd
        $handle = fopen($staging, 'w+b');

        // The staging path was just allocated by tempnam(), so it is always
        // present and writable; this guard cannot be exercised without
        // injecting a filesystem fault.
        // @codeCoverageIgnoreStart
        if ($handle === false) {
            @unlink($staging);

            throw new SinkException("Unable to open staging file [{$staging}] for the export.");
        }

        // @codeCoverageIgnoreEnd
        $written = false;

        try {
            $this->writeInto(new StreamSink($handle));

            fflush($handle);

            $written = true;
        } finally {
            fclose($handle);

            // A failure mid-write leaves a partially written staging file that
            // store() never reaches to clean up, so remove it here rather than
            // orphaning it in the system temp directory.
            if (!$written) {
                @unlink($staging);
            }
        }

        return $staging;
    }

    /**
     * Stream the export into the given sink and return the row count.
     *
     * An optional progress callback receives the running row count as each row
     * is written, so a streaming caller can report how many rows reached the
     * client at the point a mid-stream failure truncates the body.
     *
     * @param  \SineMacula\Exporter\Contracts\Sink  $sink
     * @param  (\Closure(int): void)|null  $onProgress
     * @param  bool  $abortAware
     * @return int
     *
     * @throws \SineMacula\Exporter\Exceptions\NoTabularRepresentation
     */
    private function writeInto(Sink $sink, ?\Closure $onProgress = null, bool $abortAware = false): int
    {
        $rows    = 0;
        $request = $this->currentRequest();
        $source  = SourceFactory::for($this->subject, $this->chunkSize);

        if ($abortAware) {
            $source = new ConnectionAwareSource($source, $this->abortSignal ?? static fn (): bool => connection_aborted() === 1);
        }

        if ($this->registry->isTabular($this->format)) {

            $writer = $this->registry->writerFor($this->format);

            if ($writer === null) {
                throw NoTabularRepresentation::forResource($this->format);
            }

            $counting = new CountingWriter($writer, static function (int $count) use (&$rows, $onProgress): void {
                $rows = $count;

                $onProgress?->__invoke($count);
            });

            $warnings = new WarningCollector;

            $this->engine->export($source, $this->resolveSchema(), $request, $counting, $sink, $warnings, $this->with);

            if (!$warnings->isEmpty()) {
                Log::warning('Resource export completed with warnings.', [
                    'format'   => $this->format,
                    'warnings' => $warnings->all(),
                ]);
            }

            return $rows;
        }

        $writer = $this->registry->hierarchicalWriterFor($this->format);

        if ($writer === null) {
            throw NoTabularRepresentation::forResource($this->format);
        }

        $resolver = new HierarchicalRows($this->resourceClassOrNull());

        $source = $source->withRelations($this->with);

        $writer->write($resolver->resolve($source, $request, static function () use (&$rows, $onProgress): void {
            $rows++;

            $onProgress?->__invoke($rows);
        }), $sink);

        return $rows;
    }

    /**
     * Count the source rows the export would emit, without producing bytes.
     *
     * @return int
     */
    private function countRows(): int
    {
        $rows = SourceFactory::for($this->subject, $this->chunkSize)->rows();

        return is_array($rows) ? count($rows) : iterator_count($rows);
    }

    /**
     * Resolve the tabular schema for the export, or throw a 406.
     *
     * @return \SineMacula\Exporter\Schema\TabularSchema
     *
     * @throws \SineMacula\Exporter\Exceptions\NoTabularRepresentation
     */
    private function resolveSchema(): TabularSchema
    {
        $schema = $this->schema;

        if ($schema instanceof TabularSchema) {
            return $schema;
        }

        $request = $this->currentRequest();

        if (is_string($schema)) {
            return new $schema($request);
        }

        $subject = $this->subject;
        $class   = $this->resourceClassOrNull();

        $provider = match (true) {
            $subject instanceof ProvidesTabularExport                           => $subject,
            $class !== null && is_a($class, ProvidesTabularExport::class, true) => new $class(null),
            default                                                             => null,
        };

        if ($provider === null) {
            throw NoTabularRepresentation::forResource($class ?? get_debug_type($subject));
        }

        return $provider->tabular($request);
    }

    /**
     * Resolve the resource class for the subject, if one is known.
     *
     * @return class-string<\Illuminate\Http\Resources\Json\JsonResource>|null
     */
    private function resourceClassOrNull(): ?string
    {
        if ($this->resourceClass !== null) {
            return $this->resourceClass;
        }

        $subject = $this->subject;

        if ($subject instanceof ResourceCollection && is_a($subject->collects, JsonResource::class, true)) {
            return $subject->collects;
        }

        return $subject instanceof JsonResource ? $subject::class : null;
    }

    /**
     * Resolve the filename hint for a download.
     *
     * @return string
     */
    private function filenameHint(): string
    {
        if ($this->filename !== null) {
            return $this->filename;
        }

        if ($this->registry->isTabular($this->format)) {

            $name = $this->resolveSchema()->filename();

            if ($name !== null) {
                return $name;
            }
        }

        return 'export';
    }

    /**
     * Resolve the request the schema and resources are built for.
     *
     * @return \Illuminate\Http\Request
     */
    private function currentRequest(): Request
    {
        if ($this->request !== null) {
            return $this->request;
        }

        $request = Container::getInstance()->make('request');

        return $request instanceof Request ? $request : Request::create('/');
    }
}
