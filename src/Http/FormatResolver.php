<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\AcceptHeader;

/**
 * Export format resolver.
 *
 * The package's own negotiation resolver (Laravel's prefers() collapses
 * wildcards to the first configured type and ignores quality, so it cannot be
 * used). Precedence is: an explicit ?format= query parameter or a whitelisted
 * URL extension, then the Accept header at its highest quality (filtering q=0
 * entries), then the configured default. JSON is a first-class candidate so a
 * wildcard or empty Accept resolves to it. The resolver is request-explicit and
 * holds no per-request state, so it is safe to reuse under Octane.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class FormatResolver
{
    /**
     * Create a new format resolver.
     *
     * @param  \SineMacula\Exporter\Http\MediaTypeRegistry  $registry
     * @param  string  $queryParameter
     */
    public function __construct(

        /** The media type registry resolving formats. */
        private MediaTypeRegistry $registry = new MediaTypeRegistry,

        /** The query parameter name carrying an explicit format. */
        private string $queryParameter = 'format',
    ) {}

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
     * Determine whether the request explicitly selected a format through the
     * query parameter or a whitelisted URL extension.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    public function isExplicitFormat(Request $request): bool
    {
        return $this->resolveFromParameter($request) !== null
            || $this->resolveFromExtension($request) !== null;
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

        foreach ($this->highestQualityValues($header) as $value) {

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
     * Get the Accept media-type values in the most-preferred quality band.
     *
     * Entries the client rejects with q=0 are dropped, and only the single
     * highest quality present is kept. Otherwise a browser's lower-priority
     * application/xml;q=0.9 would win over the default JSON representation it
     * ranks beneath text/html.
     *
     * @param  string  $header
     * @return list<string>
     */
    private function highestQualityValues(string $header): array
    {
        $highest = null;
        $values  = [];

        foreach (AcceptHeader::fromString($header)->all() as $item) {

            $quality = $item->getQuality();

            if ($quality <= 0.0) {
                continue;
            }

            $highest ??= $quality;

            if ($quality < $highest) {
                break;
            }

            $values[] = strtolower(trim($item->getValue()));
        }

        return $values;
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
}
