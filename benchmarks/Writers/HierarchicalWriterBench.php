<?php

declare(strict_types = 1);

namespace Benchmarks\Writers;

use Benchmarks\Support\Resources\BenchmarkResource;
use Benchmarks\Support\Rows\RowFactory;
use Benchmarks\Support\Sources\ArraySource;
use Illuminate\Http\Request;
use PhpBench\Attributes as Bench;
use SineMacula\Exporter\Export\HierarchicalRows;
use SineMacula\Exporter\Sinks\StringSink;
use SineMacula\Exporter\Writers\JsonWriter;
use SineMacula\Exporter\Writers\NdjsonWriter;
use SineMacula\Exporter\Writers\XmlWriter;

/**
 * Benchmarks for hierarchical streaming writers.
 *
 * Exercises nested array/list serialization for JSON, NDJSON and XML writers,
 * which are the negotiated hierarchical paths not covered by raw array driver
 * benchmarks.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\OutputTimeUnit('milliseconds')]
final class HierarchicalWriterBench
{
    /** @var int The hierarchical item count used for each writer. */
    private const int ROWS = 5000;

    /** @var list<array<string, bool|float|int|string|null>> The resource rows resolved in the production-path subject. */
    private array $resourceRows = [];

    /** @var \Illuminate\Http\Request The request used for resource resolution. */
    private Request $request;

    /**
     * Create the benchmark request.
     */
    public function __construct()
    {
        $this->request = Request::create('/');
    }

    /**
     * Build rows used by the resource-resolution subject.
     *
     * @return void
     */
    public function setUp(): void
    {
        $this->resourceRows = RowFactory::make(self::ROWS);
    }

    /**
     * Benchmark streaming a JSON array.
     *
     * @return void
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(5)]
    #[Bench\Warmup(1)]
    public function benchJsonWriterNested(): void
    {
        (new JsonWriter)->write(RowFactory::hierarchical(self::ROWS), new StringSink);
    }

    /**
     * Benchmark streaming newline-delimited JSON.
     *
     * @return void
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(5)]
    #[Bench\Warmup(1)]
    public function benchNdjsonWriterNested(): void
    {
        (new NdjsonWriter)->write(RowFactory::hierarchical(self::ROWS), new StringSink);
    }

    /**
     * Benchmark resolving raw source rows through a resource into NDJSON.
     *
     * @return void
     */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\Iterations(5)]
    #[Bench\Revs(5)]
    #[Bench\Warmup(1)]
    public function benchNdjsonWriterResolvedResources(): void
    {
        $rows = (new HierarchicalRows(BenchmarkResource::class))->resolve(
            new ArraySource($this->resourceRows),
            $this->request,
            static fn (): null => null,
        );

        (new NdjsonWriter)->write($rows, new StringSink);
    }

    /**
     * Benchmark streaming XML.
     *
     * @return void
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(3)]
    #[Bench\Warmup(1)]
    public function benchXmlWriterNested(): void
    {
        (new XmlWriter)->write(RowFactory::hierarchical(self::ROWS), new StringSink);
    }
}
