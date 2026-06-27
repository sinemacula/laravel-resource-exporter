<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use SineMacula\Exporter\Contracts\ProvidesTabularExport;
use SineMacula\Exporter\Contracts\Sink;
use SineMacula\Exporter\Contracts\Source;
use SineMacula\Exporter\Engine;
use SineMacula\Exporter\Exceptions\NoTabularRepresentation;
use SineMacula\Exporter\Schema\TabularSchema;
use SineMacula\Exporter\Sinks\StreamedResponseSink;
use SineMacula\Exporter\Sources\ResourceCollectionSource;
use SineMacula\Exporter\Sources\ResourceItemSource;
use Symfony\Component\HttpFoundation\AcceptHeader;
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
    /**
     * Create a new export negotiator.
     *
     * @param  \SineMacula\Exporter\Http\MediaTypeRegistry  $registry
     * @param  \SineMacula\Exporter\Engine  $engine
     * @param  string  $queryParameter
     */
    public function __construct(

        /** The media type registry resolving formats. */
        private MediaTypeRegistry $registry = new MediaTypeRegistry,

        /** The export engine streaming rows into a writer. */
        private Engine $engine = new Engine,

        /** The query parameter name carrying an explicit format. */
        private string $queryParameter = 'format',
    ) {}

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
        return $this->resolveFromParameter($request)
            ?? $this->resolveFromExtension($request)
            ?? $this->resolveFromAccept($request)
            ?? $this->registry->defaultFormat();
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
     * Returns null when the negotiated format is hierarchical so the caller can
     * fall back to the resource's own JSON response; otherwise streams the item
     * as a one-row tabular export, or throws a 406 when the resource provides
     * no tabular schema.
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

        if (!$this->isTabular($format)) {
            return null;
        }

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

    /**
     * Negotiate a top-level resource collection.
     *
     * Returns null when the negotiated format is hierarchical so the caller can
     * fall back to the framework's JSON collection response; otherwise streams
     * the underlying items as a tabular export, or throws a 406 when the item
     * resource provides no tabular schema.
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

        if (!$this->isTabular($format)) {
            return null;
        }

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

    /**
     * Stream a source through a schema and writer as a tabular download.
     *
     * @param  \SineMacula\Exporter\Contracts\Source  $source
     * @param  \SineMacula\Exporter\Schema\TabularSchema  $schema
     * @param  string  $format
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     *
     * @throws \SineMacula\Exporter\Exceptions\NoTabularRepresentation
     */
    public function streamExport(Source $source, TabularSchema $schema, string $format, Request $request): StreamedResponse
    {
        $writer = $this->registry->writerFor($format);

        if ($writer === null) {
            throw NoTabularRepresentation::forResource($format);
        }

        $sink = new StreamedResponseSink;

        return $sink->toResponse(
            function (Sink $stream) use ($source, $schema, $request, $writer): void {
                $this->engine->export($source, $schema, $request, $writer, $stream);
            },
            200,
            [
                'Content-Type'        => $writer->mediaType() . '; charset=UTF-8',
                'Content-Disposition' => $this->disposition($schema, $format),
                'Vary'                => 'Accept',
            ],
        );
    }

    /**
     * Resolve the format from an explicit, whitelisted ?format= parameter.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    private function resolveFromParameter(Request $request): ?string
    {
        $value = $request->query($this->queryParameter);

        if (!is_string($value) || $value === '') {
            return null;
        }

        $value = strtolower($value);

        return $this->registry->has($value) ? $value : null;
    }

    /**
     * Resolve the format from a whitelisted URL extension suffix.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    private function resolveFromExtension(Request $request): ?string
    {
        $extension = strtolower(pathinfo($request->path(), PATHINFO_EXTENSION));

        if ($extension === '') {
            return null;
        }

        return $this->registry->formatForExtension($extension);
    }

    /**
     * Resolve the format from the Accept header at its highest quality.
     *
     * Entries with q=0 are filtered (the client explicitly rejects them) and
     * wildcards resolve to JSON-first, matching the design's negotiation rules.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    private function resolveFromAccept(Request $request): ?string
    {
        $header = $request->headers->get('Accept');

        if ($header === null || trim($header) === '') {
            return null;
        }

        foreach (AcceptHeader::fromString($header)->all() as $item) {

            if ($item->getQuality() <= 0.0) {
                continue;
            }

            $value = strtolower(trim($item->getValue()));

            $match = str_contains($value, '*')
                ? $this->matchWildcard($value)
                : $this->registry->formatForMediaType($value);

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * Resolve a wildcard Accept value, preferring the default (JSON) format.
     *
     * @param  string  $value
     * @return string|null
     */
    private function matchWildcard(string $value): ?string
    {
        if ($value === '*' || $value === '*/*') {
            return $this->registry->defaultFormat();
        }

        if (!str_ends_with($value, '/*')) {
            return null;
        }

        return $this->matchTypeWildcard(substr($value, 0, -1));
    }

    /**
     * Resolve a "type/*" wildcard to a format, preferring the default.
     *
     * @param  string  $type
     * @return string|null
     */
    private function matchTypeWildcard(string $type): ?string
    {
        $default = $this->registry->get($this->registry->defaultFormat());

        if ($default !== null && str_starts_with($default->defaultMediaType(), $type)) {
            return $default->name();
        }

        foreach ($this->registry->all() as $format) {
            if (str_starts_with($format->defaultMediaType(), $type)) {
                return $format->name();
            }
        }

        return null;
    }

    /**
     * Build the Content-Disposition header for the negotiated download.
     *
     * The schema filename is sanitised of path separators and given an ASCII
     * fallback so makeDisposition() cannot reject it on control characters or
     * non-ASCII bytes.
     *
     * @param  \SineMacula\Exporter\Schema\TabularSchema  $schema
     * @param  string  $format
     * @return string
     */
    private function disposition(TabularSchema $schema, string $format): string
    {
        $extension = $this->registry->get($format)?->extension() ?? $format;
        $base      = str_replace(['/', '\\'], '_', $schema->filename() ?? 'export');
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
