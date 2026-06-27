<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Writers;

use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxLibraryWriter;
use SineMacula\Exporter\Contracts\Sink;
use SineMacula\Exporter\Contracts\Writer;
use SineMacula\Exporter\Exceptions\MissingXlsxDependency;
use SineMacula\Exporter\Exceptions\SinkException;
use SineMacula\Exporter\Exceptions\XlsxRowLimitExceeded;
use SineMacula\Exporter\Schema\CellValue;
use SineMacula\Exporter\Schema\Enums\CellType;
use SineMacula\Exporter\Schema\TabularSchema;

/**
 * Tabular XLSX writer.
 *
 * Streams the shaped rows into an XLSX workbook on top of OpenSpout, emitting
 * typed cells from each CellValue - numbers as numeric cells and dates as
 * native date cells, so a single schema drives both the textual CSV output and
 * a faithfully typed spreadsheet. OpenSpout is an optional, runtime-suggested
 * dependency, guarded with a class_exists check.
 *
 * An XLSX is a ZIP finalised on close(), so it cannot be streamed into a
 * non-seekable sink in place: the workbook is always built to a temporary file,
 * then handed to the sink. A seekable sink receives the finalised bytes through
 * its stream; a non-seekable sink (a storage disk or temp-file sink) receives
 * the file via putFromFile. The writer holds no request state and is
 * constructed per export, so it is safe under Octane.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class XlsxWriter implements Writer
{
    /** The hard cap on rows per worksheet imposed by the XLSX format. */
    private const int MAX_ROWS_PER_SHEET = 1048576;

    /**
     * Create a new XLSX writer.
     *
     * @param  string|null  $tempDirectory
     */
    public function __construct(

        /** The directory the workbook is built in, or null for the default. */
        private ?string $tempDirectory = null,
    ) {}

    /**
     * Get the media type this writer emits.
     *
     * @return string
     */
    #[\Override]
    public function mediaType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    /**
     * Write the shaped rows into the given sink as an XLSX workbook.
     *
     * @param  iterable<int, array<string, \SineMacula\Exporter\Schema\CellValue>>  $rows
     * @param  \SineMacula\Exporter\Schema\TabularSchema  $schema
     * @param  \SineMacula\Exporter\Contracts\Sink  $sink
     * @return void
     *
     * @throws \SineMacula\Exporter\Exceptions\MissingXlsxDependency
     * @throws \SineMacula\Exporter\Exceptions\XlsxRowLimitExceeded
     * @throws \SineMacula\Exporter\Exceptions\SinkException
     */
    #[\Override]
    public function write(iterable $rows, TabularSchema $schema, Sink $sink): void
    {
        $this->guardAvailable();

        $path = $this->temporaryPath();

        try {
            $this->build($rows, $schema, $path);
            $this->finalise($path, $sink);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Guard that the optional OpenSpout dependency is installed.
     *
     * @return void
     *
     * @throws \SineMacula\Exporter\Exceptions\MissingXlsxDependency
     */
    private function guardAvailable(): void
    {
        if (!class_exists(XlsxLibraryWriter::class)) {
            throw MissingXlsxDependency::create();
        }
    }

    /**
     * Build the workbook on disk, streaming the rows through OpenSpout.
     *
     * The heading row is emitted on the first data row (so an empty set yields
     * an empty workbook), and both the heading and data rows count toward the
     * worksheet cap.
     *
     * @param  iterable<int, array<string, \SineMacula\Exporter\Schema\CellValue>>  $rows
     * @param  \SineMacula\Exporter\Schema\TabularSchema  $schema
     * @param  string  $path
     * @return void
     *
     * @throws \SineMacula\Exporter\Exceptions\XlsxRowLimitExceeded
     */
    private function build(iterable $rows, TabularSchema $schema, string $path): void
    {
        $writer        = new XlsxLibraryWriter;
        $dateStyle     = (new Style)->setFormat('yyyy-mm-dd');
        $dateTimeStyle = (new Style)->setFormat('yyyy-mm-dd hh:mm:ss');

        $writer->openToFile($path);

        try {
            $headings = $this->headingMap($schema);
            $emitted  = false;
            $written  = 0;

            /** @var list<string>|null $keys */
            $keys = null;

            foreach ($rows as $row) {

                if ($keys === null) {
                    $keys = array_keys($row);
                }

                if (!$emitted && $schema->headings()) {
                    $written = $this->guardRowCount($written + 1);
                    $writer->addRow($this->headingRow($keys, $headings));
                }

                $emitted = true;
                $written = $this->guardRowCount($written + 1);

                $writer->addRow($this->dataRow($row, $keys, $dateStyle, $dateTimeStyle));
            }
        } finally {
            $writer->close();
        }
    }

    /**
     * Hand the finalised workbook to the sink.
     *
     * A seekable sink receives the bytes through its stream; a
     * non-seekable sink (an XLSX cannot stream into one in place)
     * receives the finished file.
     *
     * @param  string  $path
     * @param  \SineMacula\Exporter\Contracts\Sink  $sink
     * @return void
     *
     * @throws \SineMacula\Exporter\Exceptions\SinkException
     */
    private function finalise(string $path, Sink $sink): void
    {
        if (!$sink->isSeekableStream()) {
            $sink->putFromFile($path);

            return;
        }

        $source = fopen($path, 'rb');

        if ($source === false) {
            throw new SinkException("Unable to open file [{$path}] for the XLSX writer.");
        }

        try {
            stream_copy_to_stream($source, $sink->stream());
            fflush($sink->stream());
        } finally {
            fclose($source);
        }
    }

    /**
     * Allocate the temporary file the workbook is built in.
     *
     * @return string
     *
     * @throws \SineMacula\Exporter\Exceptions\SinkException
     */
    private function temporaryPath(): string
    {
        $path = tempnam($this->tempDirectory ?? sys_get_temp_dir(), 'export_xlsx_');

        if ($path === false) {
            throw new SinkException('Unable to allocate a temporary file for the XLSX writer.');
        }

        return $path;
    }

    /**
     * Guard the running row count against the worksheet cap.
     *
     * @param  int  $count
     * @return int
     *
     * @throws \SineMacula\Exporter\Exceptions\XlsxRowLimitExceeded
     */
    private function guardRowCount(int $count): int
    {
        if ($count > self::MAX_ROWS_PER_SHEET) {
            throw XlsxRowLimitExceeded::forLimit(self::MAX_ROWS_PER_SHEET);
        }

        return $count;
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
     * Build the heading row for the given column keys.
     *
     * @param  list<string>  $keys
     * @param  array<string, string>  $headings
     * @return \OpenSpout\Common\Entity\Row
     */
    private function headingRow(array $keys, array $headings): Row
    {
        $cells = array_map(
            static fn (string $key): Cell => new StringCell($headings[$key] ?? $key, null),
            $keys,
        );

        return new Row($cells);
    }

    /**
     * Build a data row of typed cells in column order.
     *
     * @param  array<string, \SineMacula\Exporter\Schema\CellValue>  $row
     * @param  list<string>  $keys
     * @param  \OpenSpout\Common\Entity\Style\Style  $dateStyle
     * @param  \OpenSpout\Common\Entity\Style\Style  $dateTimeStyle
     * @return \OpenSpout\Common\Entity\Row
     */
    private function dataRow(array $row, array $keys, Style $dateStyle, Style $dateTimeStyle): Row
    {
        $cells = array_map(
            fn (string $key): Cell => $this->cell($row[$key] ?? new CellValue(null, CellType::NULL), $dateStyle, $dateTimeStyle),
            $keys,
        );

        return new Row($cells);
    }

    /**
     * Map a typed cell value to a native OpenSpout cell.
     *
     * Numeric cells become numeric cells and dates become native date
     * cells (so Excel can sort and filter them); booleans render to their
     * configured label - so the spreadsheet reads the same as the CSV the
     * schema also drives - and everything else becomes a string cell. A
     * null becomes an empty cell.
     *
     * @param  \SineMacula\Exporter\Schema\CellValue  $cell
     * @param  \OpenSpout\Common\Entity\Style\Style  $dateStyle
     * @param  \OpenSpout\Common\Entity\Style\Style  $dateTimeStyle
     * @return \OpenSpout\Common\Entity\Cell
     */
    private function cell(CellValue $cell, Style $dateStyle, Style $dateTimeStyle): Cell
    {
        return match ($cell->type) {
            CellType::NULL      => new EmptyCell(null, null),
            CellType::INTEGER   => new NumericCell($this->renderInt($cell->raw), null),
            CellType::FLOAT     => new NumericCell($this->renderFloat($cell->raw), null),
            CellType::BOOLEAN   => new StringCell($this->renderBoolean($cell), null),
            CellType::DATE      => $this->dateCell($cell, $dateStyle),
            CellType::DATE_TIME => $this->dateCell($cell, $dateTimeStyle),
            default             => new StringCell($this->renderString($cell->raw), null),
        };
    }

    /**
     * Build a native date cell, falling back to a string when the raw value is
     * not a date instance.
     *
     * @param  \SineMacula\Exporter\Schema\CellValue  $cell
     * @param  \OpenSpout\Common\Entity\Style\Style  $style
     * @return \OpenSpout\Common\Entity\Cell
     */
    private function dateCell(CellValue $cell, Style $style): Cell
    {
        if ($cell->raw instanceof \DateTimeInterface) {
            return new DateTimeCell($cell->raw, $style);
        }

        return new StringCell($this->renderString($cell->raw), null);
    }

    /**
     * Render an integer cell to a native integer.
     *
     * @param  mixed  $raw
     * @return int
     */
    private function renderInt(mixed $raw): int
    {
        if (is_int($raw)) {
            return $raw;
        }

        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * Render a float cell to a native float.
     *
     * @param  mixed  $raw
     * @return float
     */
    private function renderFloat(mixed $raw): float
    {
        if (is_float($raw)) {
            return $raw;
        }

        return is_numeric($raw) ? (float) $raw : 0.0;
    }

    /**
     * Render a boolean cell to its configured label.
     *
     * @param  \SineMacula\Exporter\Schema\CellValue  $cell
     * @return string
     */
    private function renderBoolean(CellValue $cell): string
    {
        $labels = $cell->format !== null && str_contains($cell->format, '|')
            ? explode('|', $cell->format, 2)
            : ['Yes', 'No'];

        return $cell->raw ? $labels[0] : ($labels[1] ?? 'No');
    }

    /**
     * Render an arbitrary value to its string representation.
     *
     * @param  mixed  $raw
     * @return string
     */
    private function renderString(mixed $raw): string
    {
        if (is_string($raw)) {
            return $raw;
        }

        if (is_scalar($raw) || $raw instanceof \Stringable) {
            return (string) $raw;
        }

        return '';
    }
}
