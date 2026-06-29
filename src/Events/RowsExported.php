<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Events;

/**
 * Rows exported progress event.
 *
 * Fired periodically by the queued export pipeline as rows are streamed to
 * disk, carrying the running row count so listeners can surface progress
 * without the export materialising the set. It is emitted every configured
 * number of rows rather than per row, so progress reporting never dominates the
 * export's cost.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class RowsExported
{
    /**
     * Create a new rows exported progress event.
     *
     * @param  int  $rows
     * @param  string  $format
     * @param  string  $disk
     * @param  string  $path
     */
    public function __construct(

        /** The number of data rows written so far. */
        public int $rows,

        /** The negotiated export format name. */
        public string $format,

        /** The target storage disk the export is written to. */
        public string $disk,

        /** The destination path on the disk. */
        public string $path,
    ) {}
}
