<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Exceptions;

use SineMacula\Exporter\Contracts\ExporterException;

/**
 * XLSX row limit exceeded exception.
 *
 * Thrown while writing an XLSX export once the worksheet would exceed the
 * spreadsheet format's hard cap of 1,048,576 rows per sheet (heading and data
 * rows both count). Multi-sheet rollover is a later iteration, so v3.0 fails
 * loudly and points the author at a queued export or the CSV format for larger
 * datasets.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class XlsxRowLimitExceeded extends \RuntimeException implements ExporterException
{
    /**
     * Create an exception for an export that overflows the XLSX sheet cap.
     *
     * @param  int  $limit
     * @return self
     */
    public static function forLimit(int $limit): self
    {
        return new self("The export exceeds the XLSX sheet limit of [{$limit}] rows. Use a queued export or the CSV format for larger datasets.");
    }
}
