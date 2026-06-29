<?php

declare(strict_types = 1);

namespace Benchmarks\Sources;

use Benchmarks\Support\Resources\BenchmarkResource;
use Benchmarks\Support\Rows\RowFactory;
use Benchmarks\Support\Sources\ArraySource;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\LazyCollection;
use PhpBench\Attributes as Bench;
use SineMacula\Exporter\Contracts\Source;
use SineMacula\Exporter\Sources\ConnectionAwareSource;
use SineMacula\Exporter\Sources\LazyCollectionSource;
use SineMacula\Exporter\Sources\PaginatorPageSource;
use SineMacula\Exporter\Sources\ResourceCollectionSource;

/**
 * Benchmarks for source adapters.
 *
 * Covers the normalization layer that feeds the engine: lazy collection
 * streaming, resource collection unwrapping, paginator page iteration and the
 * connection-aware decorator used by HTTP streaming.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\OutputTimeUnit('milliseconds')]
final class SourceBench
{
    /** @var int The lazy source row count. */
    private const int LAZY_ROWS = 100000;

    /** @var int The materialized source row count. */
    private const int MATERIALIZED_ROWS = 10000;

    /** @var array<int, array<string, bool|float|int|string|null>> The materialized rows. */
    private array $rows = [];

    /**
     * Build reusable source rows.
     *
     * @return void
     */
    public function setUp(): void
    {
        $this->rows = RowFactory::make(self::MATERIALIZED_ROWS);
    }

    /**
     * Benchmark a lazy collection source over a generator.
     *
     * @return void
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(5)]
    #[Bench\Warmup(1)]
    public function benchLazyCollectionSource(): void
    {
        $source = new LazyCollectionSource(LazyCollection::make(
            static fn (): \Generator => RowFactory::hierarchical(self::LAZY_ROWS),
        ));

        $this->consume($source);
    }

    /**
     * Benchmark resource collection unwrapping.
     *
     * @return void
     */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\Iterations(5)]
    #[Bench\Revs(10)]
    #[Bench\Warmup(1)]
    public function benchResourceCollectionSource(): void
    {
        $source = new ResourceCollectionSource(BenchmarkResource::collection($this->rows));

        $this->consume($source);
    }

    /**
     * Benchmark paginator page source iteration.
     *
     * @return void
     */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\Iterations(5)]
    #[Bench\Revs(10)]
    #[Bench\Warmup(1)]
    public function benchPaginatorPageSource(): void
    {
        $source = new PaginatorPageSource(new Paginator($this->rows, self::MATERIALIZED_ROWS));

        $this->consume($source);
    }

    /**
     * Benchmark the connection-aware decorator when the client stays connected.
     *
     * @return void
     */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\Iterations(5)]
    #[Bench\Revs(10)]
    #[Bench\Warmup(1)]
    public function benchConnectionAwareSource(): void
    {
        $source = new ConnectionAwareSource(new ArraySource($this->rows), static fn (): bool => false);

        $this->consume($source);
    }

    /**
     * Consume all source rows without retaining them.
     *
     * @param  \SineMacula\Exporter\Contracts\Source  $source
     * @return void
     */
    private function consume(Source $source): void
    {
        foreach ($source->rows() as $row) {
            unset($row);
        }
    }
}
