<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Writers\Concerns;

use SineMacula\Exporter\Exceptions\SinkException;

/**
 * Writes bytes to a sink stream, surfacing a failed write.
 *
 * The shared checked-write step behind the streaming JSON, NDJSON and XML
 * writers. A bare fwrite() returns false on a failed write (a full disk, a
 * closed pipe) without raising, so a streamed export would otherwise drop bytes
 * and still report success. Routing each data-path write through here turns
 * that silent truncation into a SinkException the writer propagates.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait WritesBytes
{
    /**
     * Write the given bytes to the stream, throwing on a failed write.
     *
     * @param  resource  $stream
     * @param  string  $bytes
     * @return void
     *
     * @throws \SineMacula\Exporter\Exceptions\SinkException
     */
    private function writeBytes($stream, string $bytes): void // phpcs:ignore SineMaculaLaravel.TypeHints.ParameterTypeHint.MissingNativeTypeHint
    {
        if (fwrite($stream, $bytes) === false) {
            throw new SinkException('Unable to write the export to the sink stream.');
        }
    }
}
