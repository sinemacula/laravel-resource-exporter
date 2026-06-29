<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Writers;

/**
 * Streaming truncation marker vocabulary.
 *
 * The single home for the documented, format-appropriate markers a streaming
 * writer flushes when an export throws mid-stream. A streamed response has
 * already committed its 200 status and its first bytes, so it cannot become a
 * clean error; instead each writer appends a final, clearly-marked record using
 * the constants here - a trailing CSV/TSV field, a JSON/NDJSON key, or an XML
 * element - so a consumer can detect the download is incomplete. The shared
 * reason string keeps the marker identical across every format.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class Truncation
{
    /** The reason value carried by every truncation marker. */
    public const string REASON = 'stream-truncated';

    /** The first field of the trailing CSV/TSV truncation record. */
    public const string CSV_FIELD = '#EXPORT_ERROR';

    /** The object key marking the trailing JSON/NDJSON truncation element. */
    public const string JSON_KEY = '_export_error';

    /** The element name of the trailing XML truncation node. */
    public const string XML_ELEMENT = 'export-error';
}
