<?php

declare(strict_types = 1);

namespace Benchmarks\Export;

use Benchmarks\Support\Resources\BenchmarkResource;
use Benchmarks\Support\Rows\RowFactory;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PhpBench\Attributes as Bench;
use SineMacula\Exporter\ExportBuilder;

/**
 * Benchmarks for the fluent export builder.
 *
 * Exercises the public explicit-export API over a resource collection, covering
 * SourceFactory, schema/resource resolution, CountingWriter, Engine and writer
 * integration for tabular and hierarchical formats.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\OutputTimeUnit('milliseconds')]
final class ExportBuilderBench
{
    /** @var int The resource collection size. */
    private const int ROWS = 1000;

    /** @var list<array<string, bool|float|int|string|null>> The rows exported by the builder. */
    private array $rows = [];

    /** @var \Illuminate\Http\Request The request passed to resources and schemas. */
    private Request $request;

    /**
     * Create the benchmark request.
     */
    public function __construct()
    {
        $this->request = Request::create('/');
    }

    /**
     * Build reusable rows and request.
     *
     * @return void
     */
    public function setUp(): void
    {
        $container = new Container;
        $container->instance('config', new Repository([
            'exporter.default' => 'csv',
        ]));

        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        $this->rows = RowFactory::make(self::ROWS);
    }

    /**
     * Benchmark tabular CSV export through the fluent builder.
     *
     * @return void
     */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\Iterations(5)]
    #[Bench\Revs(10)]
    #[Bench\Warmup(1)]
    public function benchBuilderCsvToString(): void
    {
        $this->builder('csv')->toString();
    }

    /**
     * Benchmark hierarchical NDJSON export through the fluent builder.
     *
     * @return void
     */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\Iterations(5)]
    #[Bench\Revs(10)]
    #[Bench\Warmup(1)]
    public function benchBuilderNdjsonToString(): void
    {
        $this->builder('ndjson')->toString();
    }

    /**
     * Benchmark streaming into a caller-owned resource through the builder.
     *
     * @return void
     */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\Iterations(5)]
    #[Bench\Revs(10)]
    #[Bench\Warmup(1)]
    public function benchBuilderCsvToStream(): void
    {
        $stream = fopen('php://temp', 'w+b');

        if (!is_resource($stream)) {
            return;
        }

        try {
            $this->builder('csv')->toStream($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Build an export builder for the configured format.
     *
     * @param  string  $format
     * @return \SineMacula\Exporter\ExportBuilder
     */
    private function builder(string $format): ExportBuilder
    {
        return (new ExportBuilder(BenchmarkResource::collection($this->rows)))
            ->format($format)
            ->request($this->request);
    }
}
