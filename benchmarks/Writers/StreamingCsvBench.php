<?php

declare(strict_types = 1);

namespace Benchmarks\Writers;

use Benchmarks\Support\Schema\ScaleSchema;
use Illuminate\Http\Request;
use PhpBench\Attributes as Bench;
use SineMacula\Exporter\Schema\CellValue;
use SineMacula\Exporter\Schema\Enums\CellType;
use SineMacula\Exporter\Sinks\StringSink;
use SineMacula\Exporter\Writers\CsvWriter;

/**
 * Scale benchmarks for the streaming CSV writer.
 *
 * Streams 10k, 100k and one million synthetic rows from a generator through the
 * CSV writer into a throwaway php://temp buffer, so PHPBench records both the
 * time and the peak memory at each order of magnitude. The headline result is
 * that mem_peak stays flat as the row count grows - the constant-memory proof
 * the release gate asserts - because each row is shaped, written and discarded
 * one at a time rather than buffered.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\OutputTimeUnit('milliseconds')]
final class StreamingCsvBench
{
    /** @var int The small scale dataset size. */
    private const int ROWS_10K = 10000;

    /** @var int The medium scale dataset size. */
    private const int ROWS_100K = 100000;

    /** @var int The headline scale dataset size. */
    private const int ROWS_1M = 1000000;

    /** @var \Benchmarks\Support\Schema\ScaleSchema The fixed three-column schema shared by every benchmark subject */
    private readonly ScaleSchema $schema;

    /**
     * Create the benchmark with its fixed, stateless schema.
     */
    public function __construct()
    {
        $this->schema = new ScaleSchema(Request::create('/'));
    }

    /**
     * Benchmark streaming ten thousand rows to CSV.
     *
     * @return void
     */
    #[Bench\Iterations(3)]
    #[Bench\Revs(2)]
    public function benchStreamCsv10k(): void
    {
        $this->stream(self::ROWS_10K);
    }

    /**
     * Benchmark streaming one hundred thousand rows to CSV.
     *
     * @return void
     */
    #[Bench\Iterations(3)]
    #[Bench\Revs(1)]
    public function benchStreamCsv100k(): void
    {
        $this->stream(self::ROWS_100K);
    }

    /**
     * Benchmark streaming one million rows to CSV.
     *
     * @return void
     */
    #[Bench\Iterations(2)]
    #[Bench\Revs(1)]
    public function benchStreamCsv1m(): void
    {
        $this->stream(self::ROWS_1M);
    }

    /**
     * Stream the given number of generator rows through the CSV writer.
     *
     * @param  int  $rows
     * @return void
     */
    private function stream(int $rows): void
    {
        (new CsvWriter)->write($this->rows($rows), $this->schema, new StringSink);
    }

    /**
     * Lazily yield the given number of shaped, typed-cell rows.
     *
     * @param  int  $count
     * @return \Generator<int, array<string, \SineMacula\Exporter\Schema\CellValue>>
     */
    private function rows(int $count): \Generator
    {
        for ($i = 1; $i <= $count; $i++) {
            yield [
                'id'    => new CellValue($i, CellType::INTEGER),
                'name'  => new CellValue('User ' . $i, CellType::STRING),
                'score' => new CellValue($i + 0.5, CellType::FLOAT, '0.00'),
            ];
        }
    }
}
