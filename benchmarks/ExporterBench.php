<?php

declare(strict_types = 1);

namespace Benchmarks;

use Benchmarks\Support\RowFactory;
use PhpBench\Attributes as Bench;
use SineMacula\Exporter\Exporters\Csv;
use SineMacula\Exporter\Exporters\Xml;

/**
 * Benchmarks for the exporter hot paths.
 *
 * Exercises the raw array export entry points on the CSV and XML drivers across
 * two dataset sizes so throughput regressions surface against both fixed and
 * per-row costs.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\OutputTimeUnit('microseconds')]
final class ExporterBench
{
    /** @var int The number of rows in the small dataset. */
    private const int SMALL_ROWS = 100;

    /** @var int The number of rows in the large dataset. */
    private const int LARGE_ROWS = 1000;

    /** @var array<int, array<string, bool|float|int|string|null>> The small dataset. */
    private array $smallDataset = [];

    /** @var array<int, array<string, bool|float|int|string|null>> The large dataset. */
    private array $largeDataset = [];

    /**
     * Build the representative datasets before each benchmark subject runs.
     *
     * @return void
     */
    public function setUp(): void
    {
        $this->smallDataset = RowFactory::make(self::SMALL_ROWS);
        $this->largeDataset = RowFactory::make(self::LARGE_ROWS);
    }

    /**
     * Benchmark CSV export of the small dataset.
     *
     * @return void
     */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchCsvExportSmall(): void
    {
        (new Csv([]))->exportArray($this->smallDataset);
    }

    /**
     * Benchmark CSV export of the large dataset.
     *
     * @return void
     */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\Iterations(5)]
    #[Bench\Revs(100)]
    #[Bench\Warmup(2)]
    public function benchCsvExportLarge(): void
    {
        (new Csv([]))->exportArray($this->largeDataset);
    }

    /**
     * Benchmark XML export of the small dataset.
     *
     * @return void
     */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchXmlExportSmall(): void
    {
        (new Xml([]))->exportArray($this->smallDataset);
    }

    /**
     * Benchmark XML export of the large dataset.
     *
     * @return void
     */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\Iterations(5)]
    #[Bench\Revs(100)]
    #[Bench\Warmup(2)]
    public function benchXmlExportLarge(): void
    {
        (new Xml([]))->exportArray($this->largeDataset);
    }
}
