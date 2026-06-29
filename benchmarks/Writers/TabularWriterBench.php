<?php

declare(strict_types = 1);

namespace Benchmarks\Writers;

use Benchmarks\Support\Schema\MixedSchema;
use Illuminate\Http\Request;
use PhpBench\Attributes as Bench;
use SineMacula\Exporter\Schema\CellValue;
use SineMacula\Exporter\Schema\Enums\CellType;
use SineMacula\Exporter\Sinks\StringSink;
use SineMacula\Exporter\Sinks\TempFileSink;
use SineMacula\Exporter\Writers\CsvWriter;
use SineMacula\Exporter\Writers\TsvWriter;
use SineMacula\Exporter\Writers\XlsxWriter;

/**
 * Benchmarks for tabular writers across supported formats.
 *
 * CSV already has a million-row scale benchmark; these subjects compare mixed
 * typed-cell writer throughput for CSV, TSV and XLSX at practical sizes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\OutputTimeUnit('milliseconds')]
final class TabularWriterBench
{
    /** @var int The textual writer row count. */
    private const int TEXT_ROWS = 10000;

    /** @var int The XLSX row count kept lower because OpenSpout builds a workbook. */
    private const int XLSX_ROWS = 2000;

    /** @var \Benchmarks\Support\Schema\MixedSchema The shared benchmark schema. */
    private MixedSchema $schema;

    /**
     * Create the shared schema.
     */
    public function __construct()
    {
        $this->schema = new MixedSchema(Request::create('/'));
    }

    /**
     * Build the shared schema.
     *
     * @return void
     */
    public function setUp(): void
    {
        $this->schema = new MixedSchema(Request::create('/'));
    }

    /**
     * Benchmark CSV writer throughput with mixed typed cells.
     *
     * @return void
     */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\Iterations(5)]
    #[Bench\Revs(5)]
    #[Bench\Warmup(1)]
    public function benchCsvWriterMixed(): void
    {
        (new CsvWriter)->write($this->rows(self::TEXT_ROWS), $this->schema, new StringSink);
    }

    /**
     * Benchmark TSV writer throughput with mixed typed cells.
     *
     * @return void
     */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\Iterations(5)]
    #[Bench\Revs(5)]
    #[Bench\Warmup(1)]
    public function benchTsvWriterMixed(): void
    {
        (new TsvWriter)->write($this->rows(self::TEXT_ROWS), $this->schema, new StringSink);
    }

    /**
     * Benchmark XLSX writer throughput with mixed typed cells.
     *
     * @return void
     */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\Iterations(3)]
    #[Bench\Revs(1)]
    #[Bench\Warmup(1)]
    public function benchXlsxWriterMixed(): void
    {
        $sink = new TempFileSink;

        (new XlsxWriter)->write($this->rows(self::XLSX_ROWS), $this->schema, $sink);

        @unlink($sink->path());
    }

    /**
     * Lazily yield shaped rows with the same keys as MixedSchema.
     *
     * @param  int  $count
     * @return \Generator<int, array<string, \SineMacula\Exporter\Schema\CellValue>>
     */
    private function rows(int $count): \Generator
    {
        for ($index = 1; $index <= $count; $index++) {
            $date = new \DateTimeImmutable(
                '2026-01-' . str_pad((string) (($index % 28) + 1), 2, '0', STR_PAD_LEFT),
            );

            yield [
                'id'           => new CellValue($index, CellType::INTEGER),
                'first_name'   => new CellValue('Forename' . $index, CellType::STRING),
                'last_name'    => new CellValue('SURNAME' . $index, CellType::STRING),
                'email'        => new CellValue('user' . $index . '@example.com', CellType::STRING),
                'is_active'    => new CellValue($index % 2 === 0, CellType::BOOLEAN, 'Yes|No'),
                'balance'      => new CellValue($index * 1.5, CellType::FLOAT, '0.00'),
                'reference'    => new CellValue($index % 5 === 0 ? 'n/a' : 'REF-' . $index, CellType::STRING),
                'created_at'   => new CellValue($date, CellType::DATE, 'Y-m-d'),
                'display_name' => new CellValue('Forename' . $index . ' SURNAME' . $index, CellType::STRING),
            ];
        }
    }
}
