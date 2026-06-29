<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Sinks;

use SineMacula\Exporter\Contracts\Sink;
use SineMacula\Exporter\Sinks\Concerns\WritesThroughStream;

/**
 * Output stream sink.
 *
 * Streams the written bytes directly into a writable stream (php://output by
 * default) for immediate emission. Treated as a streamable sink so writers push
 * rows straight through it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class StreamSink implements Sink
{
    use WritesThroughStream;

    /**
     * Constructor.
     *
     * @param  resource|null  $stream
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    public function __construct($stream = null) // phpcs:ignore SineMaculaLaravel.TypeHints.ParameterTypeHint.MissingNativeTypeHint
    {
        if ($stream !== null && !is_resource($stream)) {
            throw new \InvalidArgumentException('The stream sink requires a valid stream resource.');
        }

        $this->streamHandle = $stream;
    }

    /**
     * Get the stream target this sink opens.
     *
     * @return string
     */
    #[\Override]
    protected function streamTarget(): string
    {
        return 'php://output';
    }

    /**
     * Get the fopen mode used to open the stream target.
     *
     * @return string
     */
    #[\Override]
    protected function streamMode(): string
    {
        return 'wb';
    }

    /**
     * Get the human label this sink's open-failure messages carry.
     *
     * @return string
     */
    #[\Override]
    protected function streamLabel(): string
    {
        return 'stream';
    }
}
