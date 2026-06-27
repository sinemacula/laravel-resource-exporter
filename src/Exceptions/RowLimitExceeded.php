<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Exceptions;

/**
 * Row limit exceeded exception.
 *
 * Thrown in preflight when a full-dataset export would emit more rows than the
 * configured cap, before any bytes are streamed. The cap guards an endpoint
 * against an unbounded export; call unlimited() on the builder to lift it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RowLimitExceeded extends \RuntimeException
{
    /**
     * Create an exception for an export whose row count exceeds the cap.
     *
     * @param  int  $count
     * @param  int  $limit
     * @return self
     */
    public static function forCount(int $count, int $limit): self
    {
        return new self("The export of [{$count}] rows exceeds the configured cap of [{$limit}]. Call unlimited() to lift it.");
    }
}
