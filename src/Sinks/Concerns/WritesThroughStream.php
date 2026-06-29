<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Sinks\Concerns;

use SineMacula\Exporter\Exceptions\SinkException;

/**
 * Seekable streaming-sink behaviour.
 *
 * The lazy-open stream(), the finalise-then-upload putFromFile(), and the
 * seekable flag shared by every sink that streams its bytes through a single
 * seekable resource (the raw output stream, a streamed response, or an
 * in-memory string buffer). Each using sink declares only what differs - the
 * stream target it opens, the open mode, and the label its open-failure
 * messages carry. The non-seekable disk and temp-file sinks finalise to a file
 * instead and do not use this trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait WritesThroughStream
{
    /** @var resource|null The lazily-opened underlying stream */
    private $streamHandle; // phpcs:ignore SineMaculaLaravel.TypeHints.PropertyTypeHint.MissingNativeTypeHint

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
     * Get the underlying stream, opening it lazily on first use.
     *
     * @return resource
     *
     * @throws \SineMacula\Exporter\Exceptions\SinkException
     */
    #[\Override]
    public function stream() // phpcs:ignore SineMaculaLaravel.TypeHints.ReturnTypeHint.MissingNativeTypeHint
    {
        if (!is_resource($this->streamHandle)) {

            $stream = fopen($this->streamTarget(), $this->streamMode());

            // php://output and php://temp are always-openable stream wrappers,
            // so this guard needs a filesystem fault to exercise.
            // @codeCoverageIgnoreStart
            if ($stream === false) {
                throw new SinkException(sprintf('Unable to open %s for the %s sink.', $this->streamTarget(), $this->streamLabel()));
            }
            // @codeCoverageIgnoreEnd
            $this->streamHandle = $stream;
        }

        return $this->streamHandle;
    }

    /**
     * Copy a fully-written file into the underlying stream.
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
            throw new SinkException(sprintf('Unable to open file [%s] for the %s sink.', $path, $this->streamLabel()));
        }

        try {
            if (stream_copy_to_stream($source, $this->stream()) === false) {
                throw new SinkException(sprintf('Unable to copy the export into the %s sink.', $this->streamLabel()));
            }
        } finally {
            fclose($source);
        }
    }

    /**
     * Get the stream target this sink opens (e.g. php://output).
     *
     * @return string
     */
    abstract protected function streamTarget(): string;

    /**
     * Get the fopen mode used to open the stream target.
     *
     * @return string
     */
    abstract protected function streamMode(): string;

    /**
     * Get the human label this sink's open-failure messages carry.
     *
     * @return string
     */
    abstract protected function streamLabel(): string;
}
