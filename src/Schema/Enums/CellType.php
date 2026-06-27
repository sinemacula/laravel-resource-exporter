<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Schema\Enums;

/**
 * Logical cell data type.
 *
 * Carried on every CellValue so a single schema drives both string-oriented
 * formats (CSV/TSV) and typed-cell formats (XLSX): a textual writer renders
 * the formatted string while a typed writer emits a native cell of this type.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum CellType: string
{
    case STRING    = 'string';
    case INTEGER   = 'integer';
    case FLOAT     = 'float';
    case BOOLEAN   = 'boolean';
    case DATE      = 'date';
    case DATE_TIME = 'datetime';
    case NULL      = 'null';
}
