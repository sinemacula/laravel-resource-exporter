<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Sinks;

use SineMacula\Exporter\Contracts\Sink;
use SineMacula\Exporter\Exceptions\SinkException;

/**
 * Output stream sink.
 *
 * Streams the written bytes directly into a writable stream (php://output by
 * default) for immediate emission. Treated as a streamable sink so writers
 * push rows straight through it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class StreamSink implements Sink
{
    /** @var resource|null The target output stream */
    private $stream; // phpcs:ignore SineMaculaLaravel.TypeHints.PropertyTypeHint.MissingNativeTypeHint

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

        $this->stream = $stream;
    }

    /**
     * Determine whether the sink exposes a seekable stream.
     *
     * @return bool
     */
    #[\Override]
    public function isSeekableStream(): bool
    {
        return true;
    }

    /**
     * Get the underlying output stream.
     *
     * @return resource
     *
     * @throws \SineMacula\Exporter\Exceptions\SinkException
     */
    #[\Override]
    public function stream()
    {
        if (!is_resource($this->stream)) {

            $stream = fopen('php://output', 'wb');

            if ($stream === false) {
                throw new SinkException('Unable to open php://output for the stream sink.');
            }

            $this->stream = $stream;
        }

        return $this->stream;
    }

    /**
     * Copy a fully-written file into the output stream.
     *
     * @param  string  $path
     * @return void
     *
     * @throws \SineMacula\Exporter\Exceptions\SinkException
     */
    #[\Override]
    public function putFromFile(string $path): void
    {
        $source = fopen($path, 'rb');

        if ($source === false) {
            throw new SinkException("Unable to open file [{$path}] for the stream sink.");
        }

        try {
            stream_copy_to_stream($source, $this->stream());
        } finally {
            fclose($source);
        }
    }
}
