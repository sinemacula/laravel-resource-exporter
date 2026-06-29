<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Schema\Enums;

/**
 * Row-level strictness mode.
 *
 * Preflight validates everything falsifiable (schema, casts, headings, XML
 * element names, missing representation) before any bytes are sent, then makes
 * a best effort per row. Lenient skips or blanks bad cells, optionally
 * collecting warnings.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum Strictness: string
{
    case PREFLIGHT = 'preflight';
    case LENIENT   = 'lenient';
}
