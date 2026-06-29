<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Sinks;

use SineMacula\Exporter\Contracts\Sink;
use SineMacula\Exporter\Sinks\Concerns\WritesThroughStream;
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
    use WritesThroughStream;

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
        return 'streamed response';
    }
}
