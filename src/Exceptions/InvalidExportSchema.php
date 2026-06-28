<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Exceptions;

/**
 * Invalid export schema exception.
 *
 * Thrown in preflight strictness when a schema cannot be honoured: a column
 * with an empty key or an unresolvable cast, or a row-expansion declaration
 * with no relation to expand. Preflight fails fast here, before any bytes are
 * streamed, because a streamed response cannot emit a clean error mid-body; the
 * lenient mode skips the offending column or disables expansion and records a
 * warning instead.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class InvalidExportSchema extends \LogicException
{
    /**
     * Create an exception for an invalid column.
     *
     * @param  string  $key
     * @param  string  $problem
     * @return self
     */
    public static function column(string $key, string $problem): self
    {
        return new self("Invalid export column [{$key}]: {$problem}.");
    }

    /**
     * Create an exception for an invalid row-expansion declaration.
     *
     * @param  string  $problem
     * @return self
     */
    public static function expand(string $problem): self
    {
        return new self("Invalid row expansion: {$problem}.");
    }
}
