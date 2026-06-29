<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Writers;

use SineMacula\Exporter\Contracts\Sink;
use SineMacula\Exporter\Contracts\Writer;
use SineMacula\Exporter\Schema\TabularSchema;

/**
 * Streaming TSV writer.
 *
 * The tab-separated variant of the CSV writer: it shares the same league/csv
 * pipeline (formula-injection escaping on by default, configurable enclosure,
 * escape, end-of-line, BOM, and flush threshold) with the delimiter pinned to a
 * tab, and reports the tab-separated-values media type. Stateless and
 * constructed per export, so it is safe under Octane.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class TsvWriter implements Writer
{
    /** @var \SineMacula\Exporter\Writers\CsvWriter The tab-delimited CSV writer the TSV output delegates to */
    private CsvWriter $writer;

    /**
     * Create a new TSV writer.
     *
     * @param  string  $enclosure
     * @param  string  $escape
     * @param  string  $endOfLine
     * @param  bool  $bom
     * @param  bool  $escapeFormula
     * @param  int|null  $flushThreshold
     */
    public function __construct(
        string $enclosure = '"',
        string $escape = '',
        string $endOfLine = "\n",
        bool $bom = false,
        bool $escapeFormula = true,
        ?int $flushThreshold = null,
    ) {
        $this->writer = new CsvWriter("\t", $enclosure, $escape, $endOfLine, $bom, $escapeFormula, $flushThreshold);
    }

    /**
     * Get the media type this writer emits.
     *
     * @return string
     */
    #[\Override]
    public function mediaType(): string
    {
        return 'text/tab-separated-values';
    }

    /**
     * Write the shaped rows into the given sink as TSV.
     *
     * @param  iterable<int, array<string, \SineMacula\Exporter\Schema\CellValue>>  $rows
     * @param  \SineMacula\Exporter\Schema\TabularSchema  $schema
     * @param  \SineMacula\Exporter\Contracts\Sink  $sink
     * @return void
     */
    #[\Override]
    public function write(iterable $rows, TabularSchema $schema, Sink $sink): void
    {
        $this->writer->write($rows, $schema, $sink);
    }
}
