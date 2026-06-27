<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Sinks;

use SineMacula\Exporter\Contracts\Sink;
use SineMacula\Exporter\Exceptions\SinkException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streamed response sink.
 *
 * Streams the written bytes through a Symfony StreamedResponse callback so the
 * export reaches the client at constant memory. The output stream is opened
 * lazily while the response is being sent, so writers stream rows straight to
 * the browser.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class StreamedResponseSink implements Sink
{
    /** @var resource|null The output stream, opened during the response send */
    private $stream; // phpcs:ignore SineMaculaLaravel.TypeHints.PropertyTypeHint.MissingNativeTypeHint

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
     * Get the output stream the response is streamed through.
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
                throw new SinkException('Unable to open php://output for the streamed response sink.');
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
            throw new SinkException("Unable to open file [{$path}] for the streamed response sink.");
        }

        try {
            stream_copy_to_stream($source, $this->stream());
        } finally {
            fclose($source);
        }
    }

    /**
     * Build a streamed response that drives the given producer over this sink.
     *
     * The producer is invoked while the response is being sent and receives
     * this sink, into which a writer streams the export.
     *
     * @param  \Closure(\SineMacula\Exporter\Contracts\Sink): void  $producer
     * @param  int  $status
     * @param  array<string, list<string>|string>  $headers
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function toResponse(\Closure $producer, int $status = 200, array $headers = []): StreamedResponse
    {
        return new StreamedResponse(function () use ($producer): void {
            $producer($this);
        }, $status, $headers);
    }
}
