<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Sinks;

use SineMacula\Exporter\Contracts\Sink;
use SineMacula\Exporter\Exceptions\SinkException;

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
    /** @var resource|null The underlying buffer stream */
    private $buffer; // phpcs:ignore SineMaculaLaravel.TypeHints.PropertyTypeHint.MissingNativeTypeHint

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
     * Get the underlying buffer stream.
     *
     * @return resource
     *
     * @throws \SineMacula\Exporter\Exceptions\SinkException
     */
    #[\Override]
    public function stream()
    {
        if (!is_resource($this->buffer)) {

            $buffer = fopen('php://temp', 'r+b');

            if ($buffer === false) {
                throw new SinkException('Unable to open an in-memory buffer for the string sink.');
            }

            $this->buffer = $buffer;
        }

        return $this->buffer;
    }

    /**
     * Copy a fully-written file into the buffer.
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
            throw new SinkException("Unable to open file [{$path}] for the string sink.");
        }

        try {
            stream_copy_to_stream($source, $this->stream());
        } finally {
            fclose($source);
        }
    }

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
}
