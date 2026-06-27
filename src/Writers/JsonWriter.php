<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Writers;

use SineMacula\Exporter\Contracts\HierarchicalWriter;
use SineMacula\Exporter\Contracts\Sink;
use SineMacula\Exporter\Writers\Concerns\EncodesJson;

/**
 * Streaming JSON writer.
 *
 * Streams a top-level JSON array of each item's hierarchical array into a sink:
 * an opening bracket, the comma-separated, individually-encoded items, then a
 * closing bracket. Because each item is encoded and flushed in turn the whole
 * collection is never built in memory, yet the output is a single, valid UTF-8
 * JSON document. The writer is constructed per export and holds no request
 * state, so it is safe under Octane.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class JsonWriter implements HierarchicalWriter
{
    use EncodesJson;

    /**
     * Create a new JSON writer.
     *
     * @param  int  $flags
     */
    public function __construct(

        /** The json_encode flags applied to each item. */
        private int $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ) {}

    /**
     * Get the media type this writer emits.
     *
     * @return string
     */
    #[\Override]
    public function mediaType(): string
    {
        return 'application/json';
    }

    /**
     * Write the resolved hierarchical items to the given sink as a JSON array.
     *
     * @param  iterable<int, array<array-key, mixed>>  $items
     * @param  \SineMacula\Exporter\Contracts\Sink  $sink
     * @return void
     *
     * @throws \Throwable
     */
    #[\Override]
    public function write(iterable $items, Sink $sink): void
    {
        $stream = $sink->stream();
        $first  = true;

        fwrite($stream, '[');

        try {
            foreach ($items as $item) {

                if (!$first) {
                    fwrite($stream, ',');
                }

                $first = false;

                fwrite($stream, $this->encodeJson($item, $this->flags));
            }
        } catch (\Throwable $exception) {
            $this->markTruncated($stream, $first);

            throw $exception;
        }

        fwrite($stream, ']');

        fflush($stream);
    }

    /**
     * Close the array with a clearly-marked truncation element on failure.
     *
     * The streamed response has already committed its status and bytes, so the
     * partial array is finished with a final marker object and a closing
     * bracket, leaving the output valid JSON whose last element flags the
     * truncation, before the exception propagates.
     *
     * @param  resource  $stream
     * @param  bool  $first
     * @return void
     *
     * @throws \JsonException
     */
    private function markTruncated($stream, bool $first): void // phpcs:ignore SineMaculaLaravel.TypeHints.ParameterTypeHint.MissingNativeTypeHint
    {
        if (!$first) {
            fwrite($stream, ',');
        }

        fwrite($stream, $this->encodeJson([Truncation::JSON_KEY => Truncation::REASON], $this->flags));
        fwrite($stream, ']');

        fflush($stream);
    }
}
