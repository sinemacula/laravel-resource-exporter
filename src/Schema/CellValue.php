<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Schema;

use SineMacula\Exporter\Schema\Enums\CellType;

/**
 * Typed cell value.
 *
 * The output of the per-cell pipeline: the raw (post-cast) value, its logical
 * type, and an optional format hint (e.g. a date pattern or number mask). One
 * value object lets a textual writer render a string and a typed writer emit a
 * native cell from the same data.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class CellValue
{
    /**
     * Create a new cell value.
     *
     * @param  mixed  $raw
     * @param  \SineMacula\Exporter\Schema\Enums\CellType  $type
     * @param  string|null  $format
     */
    public function __construct(

        /** The raw, post-cast cell value. */
        public mixed $raw,

        /** The logical cell type. */
        public CellType $type = CellType::STRING,

        /** An optional format hint such as a date pattern or number mask. */
        public ?string $format = null,
    ) {}
}
