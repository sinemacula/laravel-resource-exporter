<?php

declare(strict_types = 1);

namespace Benchmarks\Engine;

use Benchmarks\Support\Rows\RowFactory;
use Benchmarks\Support\Schema\ExpandedOrdersSchema;
use Benchmarks\Support\Schema\MixedSchema;
use Benchmarks\Support\Sources\ArraySource;
use Illuminate\Http\Request;
use PhpBench\Attributes as Bench;
use SineMacula\Exporter\Engine;
use SineMacula\Exporter\Sinks\StringSink;
use SineMacula\Exporter\Writers\CsvWriter;

/**
 * Benchmarks for the end-to-end tabular engine path.
 *
 * Exercises schema preflight, visibility gates, data_get resolution,
 * resolveUsing callbacks, formatters, typed casts, row expansion and writer
 * handoff. These are the production paths bypassed by raw driver benchmarks.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\OutputTimeUnit('milliseconds')]
final class EngineBench
{
    /** @var int The small mixed dataset size. */
    private const int MIXED_ROWS_SMALL = 1000;

    /** @var int The larger mixed dataset size. */
    private const int MIXED_ROWS_LARGE = 10000;

    /** @var int The number of parent rows used for fan-out. */
    private const int EXPANDED_PARENTS = 1000;

    /** @var int The child rows yielded for each parent. */
    private const int EXPANDED_CHILDREN = 5;

    /** @var \SineMacula\Exporter\Engine The export engine under benchmark. */
    private Engine $engine;

    /** @var \Illuminate\Http\Request The request passed into the schemas. */
    private Request $request;

    /** @var \Benchmarks\Support\Schema\MixedSchema The mixed scalar schema. */
    private MixedSchema $mixedSchema;

    /** @var \Benchmarks\Support\Schema\ExpandedOrdersSchema The row-expansion schema. */
    private ExpandedOrdersSchema $expandedSchema;

    /** @var list<array<string, bool|float|int|string|null>> The small mixed dataset. */
    private array $smallDataset = [];

    /** @var list<array<string, bool|float|int|string|null>> The large mixed dataset. */
    private array $largeDataset = [];

    /** @var list<array<string, int|list<array<string, float|int|string>>|string>> The parent rows with child collections. */
    private array $expandedDataset = [];

    /**
     * Create stable benchmark collaborators.
     */
    public function __construct()
    {
        $this->engine         = new Engine;
        $this->request        = Request::create('/');
        $this->mixedSchema    = new MixedSchema($this->request);
        $this->expandedSchema = new ExpandedOrdersSchema($this->request);
    }

    /**
     * Build benchmark fixtures before each subject.
     *
     * @return void
     */
    public function setUp(): void
    {
        $this->smallDataset    = RowFactory::make(self::MIXED_ROWS_SMALL);
        $this->largeDataset    = RowFactory::make(self::MIXED_ROWS_LARGE);
        $this->expandedDataset = RowFactory::makeExpanded(self::EXPANDED_PARENTS, self::EXPANDED_CHILDREN);
    }

    /**
     * Benchmark engine shaping and CSV writing of mixed scalar rows.
     *
     * @return void
     */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\Iterations(5)]
    #[Bench\Revs(10)]
    #[Bench\Warmup(1)]
    public function benchEngineCsvMixedSmall(): void
    {
        $this->engine->export(
            new ArraySource($this->smallDataset),
            $this->mixedSchema,
            $this->request,
            new CsvWriter,
            new StringSink,
        );
    }

    /**
     * Benchmark engine shaping at a larger row count.
     *
     * @return void
     */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\Iterations(5)]
    #[Bench\Revs(2)]
    #[Bench\Warmup(1)]
    public function benchEngineCsvMixedLarge(): void
    {
        $this->engine->export(
            new ArraySource($this->largeDataset),
            $this->mixedSchema,
            $this->request,
            new CsvWriter,
            new StringSink,
        );
    }

    /**
     * Benchmark parent-to-child row expansion through the engine.
     *
     * @return void
     */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\Iterations(5)]
    #[Bench\Revs(5)]
    #[Bench\Warmup(1)]
    public function benchEngineCsvExpandedRows(): void
    {
        $this->engine->export(
            new ArraySource($this->expandedDataset),
            $this->expandedSchema,
            $this->request,
            new CsvWriter,
            new StringSink,
        );
    }
}
