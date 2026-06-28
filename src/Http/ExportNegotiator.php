<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Http;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use SineMacula\Exporter\Contracts\HierarchicalWriter;
use SineMacula\Exporter\Contracts\ProvidesTabularExport;
use SineMacula\Exporter\Contracts\Sink;
use SineMacula\Exporter\Contracts\Source;
use SineMacula\Exporter\Engine;
use SineMacula\Exporter\Events\StreamExportFailed;
use SineMacula\Exporter\Exceptions\NoTabularRepresentation;
use SineMacula\Exporter\Schema\TabularSchema;
use SineMacula\Exporter\Schema\WarningCollector;
use SineMacula\Exporter\Sinks\StreamedResponseSink;
use SineMacula\Exporter\Sources\ConnectionAwareSource;
use SineMacula\Exporter\Sources\ResourceCollectionSource;
use SineMacula\Exporter\Sources\ResourceItemSource;
use SineMacula\Exporter\Writers\CountingWriter;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export content negotiator.
 *
 * The package's own negotiation resolver (Laravel's prefers() collapses
 * wildcards to the first configured type and ignores quality, so it cannot be
 * used). Precedence is: an explicit ?format= query parameter or a whitelisted
 * URL extension, then the Accept header at its highest quality (filtering q=0
 * entries), then the configured default. JSON is a first-class candidate so a
 * wildcard or empty Accept resolves to it.
 *
 * When a tabular format is negotiated, the underlying items are streamed
 * through a Source and Writer into a StreamedResponseSink without ever
 * materialising the set; a resource that cannot describe a tabular schema
 * yields a 406. The negotiator is request-explicit and holds no per-request
 * state (Octane-safe).
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ExportNegotiator
{
    /** @var \SineMacula\Exporter\Http\FormatResolver The resolver turning a request into a negotiated format name */
    private FormatResolver $resolver;

    /**
     * Create a new export negotiator.
     *
     * @param  \SineMacula\Exporter\Http\MediaTypeRegistry  $registry
     * @param  \SineMacula\Exporter\Engine  $engine
     * @param  (\Closure(): bool)|null  $abortSignal
     * @param  string  $queryParameter
     */
    public function __construct(

        /** The media type registry resolving formats. */
        private MediaTypeRegistry $registry = new MediaTypeRegistry,

        /** The export engine streaming rows into a writer. */
        private Engine $engine = new Engine,

        /** @var (\Closure(): bool)|null The client-disconnect signal, or null for connection_aborted() */
        private ?\Closure $abortSignal = null,

        // The query parameter name carrying an explicit format.
        string $queryParameter = 'format',
    ) {
        $this->resolver = new FormatResolver($registry, $queryParameter);
    }

    /**
     * Ensure a response varies on the Accept header without duplicating it.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public static function varyAccept(Response $response): Response
    {
        $vary = $response->getVary();

        if (!in_array('Accept', $vary, true)) {
            $response->setVary(array_merge($vary, ['Accept']));
        }

        return $response;
    }

    /**
     * Resolve the negotiated format name for the request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string
     */
    public function resolve(Request $request): string
    {
        return $this->resolver->resolve($request);
    }

    /**
     * Determine whether the named format is tabular.
     *
     * @param  string  $format
     * @return bool
     */
    public function isTabular(string $format): bool
    {
        return $this->registry->isTabular($format);
    }

    /**
     * Negotiate a single top-level resource.
     *
     * A tabular format streams the item as a one-row tabular export (or 406s
     * when the resource provides no tabular schema). A hierarchical format with
     * a writer streams the item's own toArray shape; the default JSON format
     * with no explicit override returns null so the caller defers to the
     * resource's native JSON response.
     *
     * @param  \Illuminate\Http\Resources\Json\JsonResource  $resource
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response|null
     *
     * @throws \SineMacula\Exporter\Exceptions\NoTabularRepresentation
     */
    public function item(JsonResource $resource, Request $request): ?Response
    {
        $format = $this->resolve($request);

        if ($this->isTabular($format)) {

            if (!$resource instanceof ProvidesTabularExport) {
                throw NoTabularRepresentation::forResource($resource::class);
            }

            return $this->streamExport(
                new ResourceItemSource($resource),
                $resource->tabular($request),
                $format,
                $request,
            );
        }

        $writer = $this->hierarchicalWriterFor($format, $request);

        if ($writer === null) {
            return null;
        }

        $class = $resource::class;

        return $this->streamHierarchical(
            new ResourceItemSource($resource),
            fn (mixed $item): array => $this->resolveItem($class, $item, $request),
            $writer,
            $format,
            $request,
        );
    }

    /**
     * Negotiate a top-level resource collection.
     *
     * A tabular format streams the underlying items as a tabular export (or
     * 406s when the item resource provides no tabular schema). A hierarchical
     * format with a writer streams each item's own toArray shape - so a
     * resource with no tabular schema can still be exported as XML, JSON, or
     * NDJSON. The default JSON format with no explicit override returns null so
     * the caller defers to the framework's native JSON collection response.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
     * @param  class-string|null  $collects
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response|null
     *
     * @throws \SineMacula\Exporter\Exceptions\NoTabularRepresentation
     */
    public function collection(ResourceCollection $collection, ?string $collects, Request $request): ?Response
    {
        $format = $this->resolve($request);

        if ($this->isTabular($format)) {

            if ($collects === null || !is_a($collects, ProvidesTabularExport::class, true)) {
                throw NoTabularRepresentation::forResource($collects ?? $collection::class);
            }

            /** @var \SineMacula\Exporter\Contracts\ProvidesTabularExport $probe */
            $probe = new $collects(null);

            return $this->streamExport(
                new ResourceCollectionSource($collection),
                $probe->tabular($request),
                $format,
                $request,
            );
        }

        $writer = $this->hierarchicalWriterFor($format, $request);

        if ($writer === null || $collects === null) {
            return null;
        }

        return $this->streamHierarchical(
            new ResourceCollectionSource($collection),
            fn (mixed $item): array => $this->resolveItem($collects, $item, $request),
            $writer,
            $format,
            $request,
        );
    }

    /**
     * Stream a source through a schema and writer as a tabular download.
     *
     * An optional completion callback receives the number of data rows emitted
     * once the stream finishes, so a full-set caller can fire its audit event
     * with the real row count without the negotiator knowing about auditing.
     * The writer is wrapped to count rows at constant memory; the callback is
     * invoked with the final count, or skipped when none was supplied.
     *
     * @param  \SineMacula\Exporter\Contracts\Source  $source
     * @param  \SineMacula\Exporter\Schema\TabularSchema  $schema
     * @param  string  $format
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(int): void|null  $onComplete
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     *
     * @throws \SineMacula\Exporter\Exceptions\NoTabularRepresentation
     */
    public function streamExport(Source $source, TabularSchema $schema, string $format, Request $request, ?\Closure $onComplete = null): StreamedResponse
    {
        $writer = $this->registry->writerFor($format);

        if ($writer === null) {
            throw NoTabularRepresentation::forResource($format);
        }

        $sink = new StreamedResponseSink;

        return $sink->toResponse(
            function (Sink $stream) use ($source, $schema, $request, $writer, $format, $onComplete): void {
                $rows     = 0;
                $warnings = new WarningCollector;

                $counting = new CountingWriter($writer, static function (int $count) use (&$rows): void {
                    $rows = $count;
                });

                try {
                    $this->engine->export($this->abortAware($source), $schema, $request, $counting, $stream, $warnings);
                } catch (\Throwable $exception) {
                    $this->failStream($format, $rows, $request, $exception);

                    return;
                }

                $this->logWarnings($format, $warnings);

                $onComplete?->__invoke($rows);
            },
            200,
            [
                'Content-Type'        => $writer->mediaType() . '; charset=UTF-8',
                'Content-Disposition' => $this->disposition($schema->filename(), $format),
                'Vary'                => 'Accept',
            ],
        );
    }

    /**
     * Stream a source's items through a hierarchical writer as a download.
     *
     * Each underlying item is resolved to its hierarchical array on demand and
     * streamed into the writer, so the set is never materialised.
     *
     * @param  \SineMacula\Exporter\Contracts\Source  $source
     * @param  \Closure(mixed): array<array-key, mixed>  $toArray
     * @param  \SineMacula\Exporter\Contracts\HierarchicalWriter  $writer
     * @param  string  $format
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(int): void|null  $onComplete
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function streamHierarchical(
        Source $source,
        \Closure $toArray,
        HierarchicalWriter $writer,
        string $format,
        Request $request,
        ?\Closure $onComplete = null,
    ): StreamedResponse {
        $sink = new StreamedResponseSink;

        return $sink->toResponse(
            function (Sink $stream) use ($source, $toArray, $writer, $format, $request, $onComplete): void {
                $rows = 0;

                try {
                    $writer->write($this->countedRows($this->resolveRows($this->abortAware($source), $toArray), $rows), $stream);
                } catch (\Throwable $exception) {
                    $this->failStream($format, $rows, $request, $exception);

                    return;
                }

                $onComplete?->__invoke($rows);
            },
            200,
            [
                'Content-Type'        => $writer->mediaType() . '; charset=UTF-8',
                'Content-Disposition' => $this->disposition(null, $format),
                'Vary'                => 'Accept',
            ],
        );
    }

    /**
     * Resolve the hierarchical writer for a negotiated format, deferring the
     * default JSON representation to the resource's native response unless the
     * format was explicitly requested via ?format= or a URL extension.
     *
     * @param  string  $format
     * @param  \Illuminate\Http\Request  $request
     * @return \SineMacula\Exporter\Contracts\HierarchicalWriter|null
     */
    public function hierarchicalWriterFor(string $format, Request $request): ?HierarchicalWriter
    {
        if ($format === $this->registry->defaultFormat() && !$this->resolver->isExplicitFormat($request)) {
            return null;
        }

        return $this->registry->hierarchicalWriterFor($format);
    }

    /**
     * Resolve an underlying item to its hierarchical array via the resource.
     *
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resourceClass
     * @param  mixed  $item
     * @param  \Illuminate\Http\Request  $request
     * @return array<array-key, mixed>
     */
    private function resolveItem(string $resourceClass, mixed $item, Request $request): array
    {
        $resource = $item instanceof JsonResource ? $item : new $resourceClass($item);

        return $resource->resolve($request);
    }

    /**
     * Lazily resolve each source item to its hierarchical array.
     *
     * @param  \SineMacula\Exporter\Contracts\Source  $source
     * @param  \Closure(mixed): array<array-key, mixed>  $toArray
     * @return \Generator<int, array<array-key, mixed>>
     */
    private function resolveRows(Source $source, \Closure $toArray): \Generator
    {
        foreach ($source->rows() as $item) {
            yield $toArray($item);
        }
    }

    /**
     * Count each item as it streams through, recording the running total.
     *
     * The hierarchical writer has no row-counting decorator, so the count is
     * taken here at the writer boundary - giving the failure handler the row
     * context (how many items reached the client) without buffering the set.
     *
     * @param  iterable<int, array<array-key, mixed>>  $items
     * @param  int  $rows
     * @return \Generator<int, array<array-key, mixed>>
     */
    private function countedRows(iterable $items, int &$rows): \Generator
    {
        foreach ($items as $item) {
            $rows++;

            yield $item;
        }
    }

    /**
     * Wrap a source so it stops iterating the moment the client disconnects.
     *
     * @param  \SineMacula\Exporter\Contracts\Source  $source
     * @return \SineMacula\Exporter\Contracts\Source
     */
    private function abortAware(Source $source): Source
    {
        return new ConnectionAwareSource($source, $this->abortSignal ?? static fn (): bool => connection_aborted() === 1);
    }

    /**
     * Handle a mid-stream failure after the status and bytes are committed.
     *
     * The writer has already flushed its documented truncation marker, so the
     * stream simply stops cleanly here: the failure is surfaced through a
     * StreamExportFailed event carrying the row context and logged. It is never
     * re-thrown, because the 200 response can no longer become an error.
     *
     * @param  string  $format
     * @param  int  $rows
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $exception
     * @return void
     */
    private function failStream(string $format, int $rows, Request $request, \Throwable $exception): void
    {
        Event::dispatch(new StreamExportFailed($format, $rows, $this->actorId($request), $exception));

        Log::warning('Resource export stream truncated after the response had begun.', [
            'format'       => $format,
            'rows_written' => $rows,
            'exception'    => $exception,
        ]);
    }

    /**
     * Log any warnings a lenient export collected once it completes cleanly.
     *
     * Lenient strictness degrades a bad column or cell rather than failing, so
     * the export still succeeds; surfacing the collected warnings here gives an
     * operator visibility of the silent degradation without touching the body.
     *
     * @param  string  $format
     * @param  \SineMacula\Exporter\Schema\WarningCollector  $warnings
     * @return void
     */
    private function logWarnings(string $format, WarningCollector $warnings): void
    {
        if ($warnings->isEmpty()) {
            return;
        }

        Log::warning('Resource export completed with warnings.', [
            'format'   => $format,
            'warnings' => $warnings->all(),
        ]);
    }

    /**
     * Resolve the initiating actor's identifier from the request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return int|string|null
     */
    private function actorId(Request $request): int|string|null
    {
        $user = $request->user();
        $id   = $user instanceof Authenticatable ? $user->getAuthIdentifier() : null;

        return is_int($id) || is_string($id) ? $id : null;
    }

    /**
     * Build the Content-Disposition header for the negotiated download.
     *
     * The filename is sanitised of path separators and given an ASCII fallback
     * so makeDisposition() cannot reject it on control characters or non-ASCII
     * bytes.
     *
     * @param  string|null  $filename
     * @param  string  $format
     * @return string
     */
    private function disposition(?string $filename, string $format): string
    {
        $extension = $this->registry->get($format)?->extension() ?? $format;
        $base      = str_replace(['/', '\\'], '_', $filename ?? 'export');
        $filename  = $base . '.' . $extension;

        return HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $filename,
            $this->asciiFallback($filename),
        );
    }

    /**
     * Build an ASCII-safe fallback filename for the Content-Disposition header.
     *
     * @param  string  $filename
     * @return string
     */
    private function asciiFallback(string $filename): string
    {
        $ascii = (string) preg_replace('/[^\x20-\x7E]/', '', $filename);
        $ascii = str_replace(['/', '\\', '%'], '_', $ascii);

        return $ascii === '' ? 'export' : $ascii;
    }
}
