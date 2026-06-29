<?php

declare(strict_types = 1);

namespace Benchmarks;

use Benchmarks\Support\BenchmarkDatabase;
use Benchmarks\Support\BenchmarkUser;
use Benchmarks\Support\MixedSchema;
use Benchmarks\Support\OrderCountSchema;
use Benchmarks\Support\OrderJoinSchema;
use Illuminate\Http\Request;
use PhpBench\Attributes as Bench;
use SineMacula\Exporter\Engine;
use SineMacula\Exporter\Sinks\StringSink;
use SineMacula\Exporter\Sources\QueryChunkSource;
use SineMacula\Exporter\Writers\CountingWriter;
use SineMacula\Exporter\Writers\CsvWriter;

/**
 * Benchmarks for query-backed v3 tabular exports.
 *
 * Exercises the production full-set source path: Eloquent keyset chunking,
 * constrained select key repair, descending keyset order, derived aggregate
 * plans, eager-load joins, CountingWriter and CSV output.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\OutputTimeUnit('milliseconds')]
final class QueryChunkSourceBench
{
    /** @var int The number of users seeded into SQLite. */
    private const int USERS = 2000;

    /** @var int The number of orders seeded per user. */
    private const int ORDERS_PER_USER = 3;

    /** @var int The keyset chunk size used by the source. */
    private const int CHUNK_SIZE = 250;

    /** @var \SineMacula\Exporter\Engine The export engine. */
    private Engine $engine;

    /** @var \Illuminate\Http\Request The request passed to schemas. */
    private Request $request;

    /**
     * Create stable benchmark collaborators.
     */
    public function __construct()
    {
        $this->engine  = new Engine;
        $this->request = Request::create('/');
    }

    /**
     * Build the in-memory database for each subject.
     *
     * @return void
     */
    public function setUp(): void
    {
        BenchmarkDatabase::seed(self::USERS, self::ORDERS_PER_USER);
    }

    /**
     * Benchmark keyset export with a constrained select that omits the key.
     *
     * @return void
     */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\Iterations(3)]
    #[Bench\Revs(2)]
    #[Bench\Warmup(1)]
    public function benchQueryChunkCsvConstrainedSelect(): void
    {
        $query                      = BenchmarkUser::query();
        $query->getQuery()->columns = ['first_name', 'last_name', 'email', 'is_active', 'balance', 'reference', 'created_at'];

        $this->export(new QueryChunkSource($query, self::CHUNK_SIZE), new MixedSchema($this->request));
    }

    /**
     * Benchmark descending keyset export with a count aggregate.
     *
     * @return void
     */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\Iterations(3)]
    #[Bench\Revs(2)]
    #[Bench\Warmup(1)]
    public function benchQueryChunkCsvCountAggregateDesc(): void
    {
        $query                     = BenchmarkUser::query();
        $query->getQuery()->orders = [
            ['column' => 'id', 'direction' => 'desc'],
        ];

        $this->export(new QueryChunkSource($query, self::CHUNK_SIZE), new OrderCountSchema($this->request));
    }

    /**
     * Benchmark eager-loaded join aggregate export.
     *
     * @return void
     */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\Iterations(3)]
    #[Bench\Revs(1)]
    #[Bench\Warmup(1)]
    public function benchQueryChunkCsvJoinAggregate(): void
    {
        $this->export(new QueryChunkSource(BenchmarkUser::query(), self::CHUNK_SIZE), new OrderJoinSchema($this->request));
    }

    /**
     * Export a query source through the engine and counting writer.
     *
     * @param  \SineMacula\Exporter\Sources\QueryChunkSource  $source
     * @param  \Benchmarks\Support\MixedSchema|\Benchmarks\Support\OrderCountSchema|\Benchmarks\Support\OrderJoinSchema  $schema
     * @return void
     */
    private function export(QueryChunkSource $source, MixedSchema|OrderCountSchema|OrderJoinSchema $schema): void
    {
        $this->engine->export(
            $source,
            $schema,
            $this->request,
            new CountingWriter(new CsvWriter, static function (int $count): void {
                unset($count);
            }),
            new StringSink,
        );
    }
}
