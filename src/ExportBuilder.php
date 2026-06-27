<?php

declare(strict_types = 1);

namespace SineMacula\Exporter;

use Illuminate\Container\Container;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Config;
use SineMacula\Exporter\Contracts\ProvidesTabularExport;
use SineMacula\Exporter\Contracts\Sink;
use SineMacula\Exporter\Exceptions\NoTabularRepresentation;
use SineMacula\Exporter\Exceptions\SinkException;
use SineMacula\Exporter\Export\ExportFilename;
use SineMacula\Exporter\Export\HierarchicalRows;
use SineMacula\Exporter\Export\QueuedExport;
use SineMacula\Exporter\Http\MediaTypeRegistry;
use SineMacula\Exporter\Schema\TabularSchema;
use SineMacula\Exporter\Sinks\DiskSink;
use SineMacula\Exporter\Sinks\StreamedResponseSink;
use SineMacula\Exporter\Sinks\StreamSink;
use SineMacula\Exporter\Sinks\StringSink;
use SineMacula\Exporter\Sources\SourceFactory;
use SineMacula\Exporter\Testing\ExporterFake;
use SineMacula\Exporter\Writers\CountingWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fluent explicit-export builder.
 *
 * The single, top-level entry point for an explicit (non-negotiated) export.
 * It composes the existing pieces rather than re-implementing them: a subject
 * (a resource item, a resource collection, or an Eloquent query) is normalised
 * into a Source adapter, a format selects a Writer from the media registry, and
 * a tabular schema (declared explicitly, or resolved from the resource) drives
 * the Engine; the shaped rows stream into whichever Sink the chosen verb wants.
 *
 * The verbs are the community vocabulary: download() and toResponse() return a
 * streamed attachment response, store() writes to a Storage disk, toString()
 * buffers to a string, toStream() streams into a resource, and queue() hands a
 * full-set export to the queued-to-disk pipeline. While Exporter::fake() is
 * active every verb records what it would have exported instead of producing
 * bytes. The builder is request-explicit and holds no shared state.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ExportBuilder
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

    /**
     * Create a new explicit-export builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>|\Illuminate\Http\Resources\Json\JsonResource  $subject
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>|null  $resourceClass
     * @param  \SineMacula\Exporter\Http\MediaTypeRegistry  $registry
     * @param  \SineMacula\Exporter\Engine  $engine
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
     * Hand a full-set export of the query subject to the queued-to-disk
     * pipeline.
     *
     * Only a query subject can be queued, because the queue needs a
     * serializable description: the model is derived from the query and the
     * export is queued as the full set. A query that already carries
     * constraints (which cannot serialize) is rejected in favour of
     * Exporter::queue($model, $resource), whose verbs round-trip to the worker.
     *
     * @param  string  $disk
     * @param  string  $path
     * @return \Illuminate\Foundation\Bus\PendingDispatch
     *
     * @throws \LogicException
     */
    public function queue(string $disk, string $path): PendingDispatch
    {
        $subject = $this->subject;

        if (!$subject instanceof Builder) {
            throw new \LogicException('Only a query subject can be queued; use Exporter::queue($model, $resource) for queued exports.');
        }

        if ($subject->getQuery()->wheres !== []) {
            throw new \LogicException('A live query with constraints cannot be queued; use Exporter::queue($model, $resource) so the constraints serialize.');
        }

        if ($this->schema instanceof TabularSchema) {
            throw new \LogicException('A queued export needs a schema class-string, not a schema instance.');
        }

        $resource = $this->resourceClassOrNull();

        if ($resource === null) {
            throw new \LogicException('A queued export needs a resource class; pass it to Exporter::query($query, $resource).');
        }

        $queued = QueuedExport::forModel($subject->getModel()::class, $resource)
            ->format($this->format)
            ->toDisk($disk, $path);

        if (is_string($this->schema)) {
            $queued->schema($this->schema);
        }

        if ($this->filename !== null) {
            $queued->as($this->filename);
        }

        return $queued->queue();
    }

    /**
     * Build a streamed attachment response for the given filename.
     *
     * @param  string  $filename
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    private function streamedResponse(string $filename): StreamedResponse
    {
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

        return (new StreamedResponseSink)->toResponse(
            fn (Sink $sink): int => $this->writeInto($sink),
            200,
            $headers,
        );
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
        $staging = tempnam(sys_get_temp_dir(), 'export_');

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
        try {
            $this->writeInto(new StreamSink($handle));

            fflush($handle);
        } finally {
            fclose($handle);
        }

        return $staging;
    }

    /**
     * Stream the export into the given sink and return the row count.
     *
     * @param  \SineMacula\Exporter\Contracts\Sink  $sink
     * @return int
     *
     * @throws \SineMacula\Exporter\Exceptions\NoTabularRepresentation
     */
    private function writeInto(Sink $sink): int
    {
        $rows    = 0;
        $request = $this->currentRequest();
        $source  = SourceFactory::for($this->subject, $this->chunkSize);

        if ($this->registry->isTabular($this->format)) {

            $writer = $this->registry->writerFor($this->format);

            if ($writer === null) {
                throw NoTabularRepresentation::forResource($this->format);
            }

            $counting = new CountingWriter($writer, static function (int $count) use (&$rows): void {
                $rows = $count;
            });

            $this->engine->export($source, $this->resolveSchema(), $request, $counting, $sink);

            return $rows;
        }

        $writer = $this->registry->hierarchicalWriterFor($this->format);

        if ($writer === null) {
            throw NoTabularRepresentation::forResource($this->format);
        }

        $resolver = new HierarchicalRows($this->resourceClassOrNull());

        $writer->write($resolver->resolve($source, $request, static function () use (&$rows): void {
            $rows++;
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
