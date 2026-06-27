<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Sinks;

use SineMacula\Exporter\Contracts\Sink;
use SineMacula\Exporter\Sinks\Concerns\WritesThroughStream;

/**
 * String buffer sink.
 *
 * Buffers the written bytes in a seekable in-memory stream (spilling to a
 * temporary file beyond a memory threshold) so the full export can be read
 * back as a single string. Seekable, so writers stream into it directly.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class StringSink implements Sink
{
    use WritesThroughStream;

    /**
     * Read the buffered bytes back as a string.
     *
     * @return string
     */
    public function contents(): string
    {
        $buffer = $this->stream();

        rewind($buffer);

        $contents = stream_get_contents($buffer);

        return $contents === false ? '' : $contents;
    }

    /**
     * Get the stream target this sink opens.
     *
     * @return string
     */
    #[\Override]
    protected function streamTarget(): string
    {
        return 'php://temp';
    }

    /**
     * Get the fopen mode used to open the stream target.
     *
     * @return string
     */
    #[\Override]
    protected function streamMode(): string
    {
        return 'r+b';
    }

    /**
     * Get the human label this sink's open-failure messages carry.
     *
     * @return string
     */
    #[\Override]
    protected function streamLabel(): string
    {
        return 'string';
    }
}
