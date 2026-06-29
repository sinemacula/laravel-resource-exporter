<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Writers;

use SineMacula\Exporter\Contracts\Sink;
use SineMacula\Exporter\Contracts\Writer;
use SineMacula\Exporter\Schema\TabularSchema;

/**
 * Row-counting writer decorator.
 *
 * Wraps any tabular writer and counts the data rows it emits, invoking a
 * callback with the running total as each row passes through. It lets the
 * streamed and queued export paths report progress and capture the final row
 * count for the audit event without the underlying writer or the engine knowing
 * anything about counting. The count is taken at the writer boundary so it
 * reflects the rows actually written (after row expansion), and the heading row
 * - emitted by the inner writer from the schema, not the row stream - is not
 * counted. The decorator adds no buffering, so constant memory is preserved.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class CountingWriter implements Writer
{
    /**
     * Create a new counting writer.
     *
     * @param  \SineMacula\Exporter\Contracts\Writer  $writer
     * @param  \Closure(int): void  $onRow
     */
    public function __construct(

        /** The wrapped writer the rows are forwarded to. */
        private Writer $writer,

        /** The callback invoked with the running row count per row. */
        private \Closure $onRow,
    ) {}

    /**
     * Get the media type the wrapped writer emits.
     *
     * @return string
     */
    #[\Override]
    public function mediaType(): string
    {
        return $this->writer->mediaType();
    }

    /**
     * Write the shaped rows into the sink, counting each one.
     *
     * @param  iterable<int, array<string, \SineMacula\Exporter\Schema\CellValue>>  $rows
     * @param  \SineMacula\Exporter\Schema\TabularSchema  $schema
     * @param  \SineMacula\Exporter\Contracts\Sink  $sink
     * @return void
     */
    #[\Override]
    public function write(iterable $rows, TabularSchema $schema, Sink $sink): void
    {
        $this->writer->write($this->counting($rows), $schema, $sink);
    }

    /**
     * Forward each row unchanged, reporting the running count as it passes.
     *
     * @param  iterable<int, array<string, \SineMacula\Exporter\Schema\CellValue>>  $rows
     * @return \Generator<int, array<string, \SineMacula\Exporter\Schema\CellValue>>
     */
    private function counting(iterable $rows): \Generator
    {
        $count = 0;

        foreach ($rows as $row) {
            $count++;

            ($this->onRow)($count);

            yield $row;
        }
    }
}
