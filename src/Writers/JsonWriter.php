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
     * @throws \JsonException
     */
    #[\Override]
    public function write(iterable $items, Sink $sink): void
    {
        $stream = $sink->stream();
        $first  = true;

        fwrite($stream, '[');

        foreach ($items as $item) {

            if (!$first) {
                fwrite($stream, ',');
            }

            $first = false;

            fwrite($stream, $this->encodeJson($item, $this->flags));
        }

        fwrite($stream, ']');

        fflush($stream);
    }
}
