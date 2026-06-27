<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Writers\Concerns;

/**
 * Encodes JSON.
 *
 * The shared, stateless JSON encoding step behind the streaming JSON and NDJSON
 * writers: it encodes a single hierarchical item to a compact JSON fragment,
 * with UTF-8 left intact, throwing on malformed input rather than emitting a
 * broken document.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait EncodesJson
{
    /**
     * Encode a single value to a compact JSON fragment.
     *
     * @param  mixed  $value
     * @param  int  $flags
     * @return string
     *
     * @throws \JsonException
     */
    protected function encodeJson(mixed $value, int $flags): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | $flags);
    }
}
