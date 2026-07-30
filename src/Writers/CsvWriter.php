<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Writers;

use Illuminate\Support\Str;
use League\Csv\Bom;
use League\Csv\EscapeFormula;
use League\Csv\Writer as LeagueWriter;
use SineMacula\Exporter\Contracts\Sink;
use SineMacula\Exporter\Contracts\Writer;
use SineMacula\Exporter\Schema\CellValue;
use SineMacula\Exporter\Schema\Enums\CellType;
use SineMacula\Exporter\Schema\TabularSchema;

/**
 * Streaming CSV writer.
 *
 * Streams the shaped rows into a sink as delimiter-separated values on top of
 * league/csv. Spreadsheet formula-injection escaping is on by default, and the
 * delimiter, enclosure, escape, end-of-line, output BOM, and flush threshold
 * are all configurable. The writer is constructed per export and holds no
 * request state, so it is safe under Octane.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class CsvWriter implements Writer
{
    /**
     * Create a new CSV writer.
     *
     * @param  string  $delimiter
     * @param  string  $enclosure
     * @param  string  $escape
     * @param  string  $endOfLine
     * @param  bool  $bom
     * @param  bool  $escapeFormula
     * @param  int|null  $flushThreshold
     * @param  \SineMacula\Exporter\Writers\CellRenderer  $cells
     */
    public function __construct(

        /** The field delimiter. */
        private string $delimiter = ',',

        /** The field enclosure character. */
        private string $enclosure = '"',

        /** The escape character (empty for RFC-4180 quote doubling). */
        private string $escape = '',

        /** The record end-of-line sequence. */
        private string $endOfLine = "\n",

        /** Whether to emit a UTF-8 byte-order mark. */
        private bool $bom = false,

        /** Whether spreadsheet formula-injection escaping is applied. */
        private bool $escapeFormula = true,

        /** The byte threshold at which the writer flushes, or null. */
        private ?int $flushThreshold = null,

        /** The shared, stateless cell value coercion. */
        private CellRenderer $cells = new CellRenderer,
    ) {}

    /**
     * Get the media type this writer emits.
     *
     * @return string
     */
    #[\Override]
    public function mediaType(): string
    {
        return 'text/csv';
    }

    /**
     * Write the shaped rows into the given sink as CSV.
     *
     * @param  iterable<int, array<string, \SineMacula\Exporter\Schema\CellValue>>  $rows
     * @param  \SineMacula\Exporter\Schema\TabularSchema  $schema
     * @param  \SineMacula\Exporter\Contracts\Sink  $sink
     * @return void
     *
     * @throws \Throwable
     */
    #[\Override]
    public function write(iterable $rows, TabularSchema $schema, Sink $sink): void
    {
        $writer   = $this->makeWriter($sink);
        $headings = $this->headingMap($schema);
        $emitted  = false;

        if ($this->bom) {
            fwrite($sink->stream(), Bom::Utf8->value);
        }

        /** @var list<string>|null $keys */
        $keys = null;

        try {
            foreach ($rows as $row) {

                if ($keys === null) {
                    $keys = array_keys($row);
                }

                if (!$emitted && $schema->headings()) {
                    $writer->insertOne($this->headingRow($keys, $headings));
                }

                $emitted = true;

                $writer->insertOne($this->dataRow($row, $keys));
            }
        } catch (\Throwable $exception) {
            $this->markTruncated($writer, $sink);

            throw $exception;
        }

        fflush($sink->stream());
    }

    /**
     * Flush a trailing truncation record when a mid-stream failure occurs.
     *
     * The streamed response has already committed its status and bytes, so the
     * partial output is finished with a clearly-marked final record before the
     * exception propagates - leaving the consumer a detectable signal that the
     * download is incomplete.
     *
     * @param  \League\Csv\Writer  $writer
     * @param  \SineMacula\Exporter\Contracts\Sink  $sink
     * @return void
     *
     * @throws \League\Csv\Exception
     */
    private function markTruncated(LeagueWriter $writer, Sink $sink): void
    {
        $writer->insertOne([Truncation::CSV_FIELD]);

        fflush($sink->stream());
    }

    /**
     * Build a configured league/csv writer over the sink stream.
     *
     * @param  \SineMacula\Exporter\Contracts\Sink  $sink
     * @return \League\Csv\Writer
     *
     * @throws \League\Csv\InvalidArgument
     * @throws \League\Csv\UnavailableStream
     */
    private function makeWriter(Sink $sink): LeagueWriter
    {
        $writer = LeagueWriter::from($sink->stream());

        $writer->setDelimiter($this->delimiter);
        $writer->setEnclosure($this->enclosure);
        $writer->setEscape($this->escape);
        $writer->setEndOfLine($this->endOfLine);

        if ($this->flushThreshold !== null) {
            $writer->setFlushThreshold($this->flushThreshold);
        }

        if ($this->escapeFormula) {
            $writer->addFormatter((new EscapeFormula)->escapeRecord(...));
        }

        return $writer;
    }

    /**
     * Build the key-to-heading lookup from the schema columns.
     *
     * @param  \SineMacula\Exporter\Schema\TabularSchema  $schema
     * @return array<string, string>
     */
    private function headingMap(TabularSchema $schema): array
    {
        $map = [];

        foreach ($schema->columns() as $column) {
            $map[$column->getKey()] = $column->getHeading() ?? Str::headline(str_replace('.', ' ', $column->getKey()));
        }

        return $map;
    }

    /**
     * Build the heading record for the given column keys.
     *
     * @param  list<string>  $keys
     * @param  array<string, string>  $headings
     * @return list<string>
     */
    private function headingRow(array $keys, array $headings): array
    {
        return array_map(static fn (string $key): string => $headings[$key] ?? $key, $keys);
    }

    /**
     * Build a data record by rendering each cell in column order.
     *
     * @param  array<string, \SineMacula\Exporter\Schema\CellValue>  $row
     * @param  list<string>  $keys
     * @return list<float|int|string>
     */
    private function dataRow(array $row, array $keys): array
    {
        return array_map(
            fn (string $key): float|int|string => $this->render($row[$key] ?? new CellValue(null, CellType::NULL)),
            $keys,
        );
    }

    /**
     * Render a typed cell to a textual CSV field.
     *
     * Numeric cells pass through as native scalars so they are written
     * faithfully and never mistaken for a formula; every other type is rendered
     * to a string through its format hint, leaving the formula-injection escape
     * to act on author-controlled text.
     *
     * @param  \SineMacula\Exporter\Schema\CellValue  $cell
     * @return float|int|string
     */
    private function render(CellValue $cell): float|int|string
    {
        return match ($cell->type) {
            CellType::NULL    => '',
            CellType::INTEGER => $this->cells->renderInt($cell->raw),
            CellType::FLOAT   => $this->cells->renderFloat($cell->raw),
            CellType::BOOLEAN => $this->cells->renderBoolean($cell),
            CellType::DATE,
            CellType::DATE_TIME => $this->renderDate($cell),
            default             => $this->cells->renderString($cell->raw),
        };
    }

    /**
     * Render a date cell through its format hint.
     *
     * @param  \SineMacula\Exporter\Schema\CellValue  $cell
     * @return string
     */
    private function renderDate(CellValue $cell): string
    {
        if ($cell->raw instanceof \DateTimeInterface) {
            return $cell->raw->format($cell->format ?? 'Y-m-d');
        }

        return $this->cells->renderString($cell->raw);
    }
}
