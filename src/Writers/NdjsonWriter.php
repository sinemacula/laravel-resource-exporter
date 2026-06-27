<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Writers;

use SineMacula\Exporter\Contracts\HierarchicalWriter;
use SineMacula\Exporter\Contracts\Sink;
use SineMacula\Exporter\Writers\Concerns\EncodesJson;

/**
 * Streaming NDJSON writer.
 *
 * The newline-delimited variant of the JSON writer: it streams each item's
 * hierarchical array as a single compact JSON object on its own line, so a
 * consumer can read one record at a time without parsing the whole document.
 * Each line is encoded and flushed in turn, so the collection is never built in
 * memory. The writer is constructed per export and holds no request state, so
 * it is safe under Octane.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class NdjsonWriter implements HierarchicalWriter
{
    use EncodesJson;

    /**
     * Create a new NDJSON writer.
     *
     * @param  string  $endOfLine
     * @param  int  $flags
     */
    public function __construct(

        /** The record end-of-line sequence. */
        private string $endOfLine = "\n",

        /** The json_encode flags applied to each line. */
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
        return 'application/x-ndjson';
    }

    /**
     * Write the resolved hierarchical items to the given sink, one JSON object
     * per line.
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

        foreach ($items as $item) {
            fwrite($stream, $this->encodeJson($item, $this->flags) . $this->endOfLine);
        }

        fflush($stream);
    }
}
