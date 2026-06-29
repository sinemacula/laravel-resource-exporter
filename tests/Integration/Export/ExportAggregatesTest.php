<?php

declare(strict_types = 1);

namespace Tests\Integration\Export;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\Exporter\Engine;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\Concerns\HasAggregates;
use SineMacula\Exporter\Schema\EagerLoadPlan;
use SineMacula\Exporter\Schema\TabularSchema;
use SineMacula\Exporter\Sinks\StringSink;
use SineMacula\Exporter\Sources\QueryChunkSource;
use SineMacula\Exporter\Writers\CsvWriter;
use SineMacula\Exporter\Writers\XlsxWriter;
use Tests\Support\Concerns\ReadsXlsx;
use Tests\Support\ExporterTestCase;
use Tests\Support\Models\Order;
use Tests\Support\Models\User;
use Tests\Support\Schema\FlexibleSchema;

/**
 * End-to-end aggregate tests: a count, sum and join over a has-many relation,
 * driven through the keyset query source and the engine into CSV and XLSX.
 *
 * The has-many aggregates are the headline of the schema layer, so they are
 * proven against a real database: the source must auto-derive the
 * withCount()/withSum() onto the query (not load the relation per item), the
 * column must read the derived alias back into a cell, and the same schema must
 * render correctly in both a textual and a typed format.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Engine::class)]
#[CoversClass(QueryChunkSource::class)]
#[CoversClass(EagerLoadPlan::class)]
#[CoversClass(Column::class)]
#[CoversTrait(HasAggregates::class)]
final class ExportAggregatesTest extends ExporterTestCase
{
    use ReadsXlsx;

    /**
     * It renders a count aggregate over a has-many relation in CSV.
     *
     * @return void
     */
    public function testCountAggregateRendersInCsv(): void
    {
        $this->seedTwoUsersWithOrders();

        $csv = $this->exportToCsv($this->countSchema());

        self::assertStringStartsWith("ID,Name,Orders\n", $csv);
        self::assertStringContainsString("1,\"User 1\",3\n", $csv);
        self::assertStringContainsString("2,\"User 2\",1\n", $csv);
    }

    /**
     * It renders a count aggregate as a native numeric XLSX cell.
     *
     * @return void
     */
    public function testCountAggregateRendersAsTypedXlsxCell(): void
    {
        $this->seedTwoUsersWithOrders();

        $rows = $this->exportToXlsx($this->countSchema());

        self::assertSame(['ID', 'Name', 'Orders'], $rows[0]);
        self::assertSame([1, 'User 1', 3], $rows[1]);
        self::assertSame([2, 'User 2', 1], $rows[2]);
        self::assertIsInt($rows[1][2]);
    }

    /**
     * It renders a sum aggregate over a has-many relation in CSV.
     *
     * @return void
     */
    public function testSumAggregateRendersInCsv(): void
    {
        $this->seedTwoUsersWithOrders();

        $csv = $this->exportToCsv($this->sumSchema());

        self::assertStringStartsWith("ID,Name,Spend\n", $csv);
        self::assertStringContainsString("1,\"User 1\",60\n", $csv);
        self::assertStringContainsString("2,\"User 2\",5\n", $csv);
    }

    /**
     * It renders a sum aggregate as a native numeric XLSX cell.
     *
     * @return void
     */
    public function testSumAggregateRendersAsTypedXlsxCell(): void
    {
        $this->seedTwoUsersWithOrders();

        $rows = $this->exportToXlsx($this->sumSchema());

        self::assertSame(['ID', 'Name', 'Spend'], $rows[0]);
        self::assertSame([1, 'User 1', 60], $rows[1]);
        self::assertSame([2, 'User 2', 5], $rows[2]);
        self::assertIsInt($rows[1][2]);
    }

    /**
     * It folds a join aggregate over the eager-loaded relation into one cell.
     *
     * The order model is stringable (its SKU), so join() renders each child to
     * its SKU joined by the glue - exercising the join contract that a child is
     * folded through its scalar/stringable representation.
     *
     * @return void
     */
    public function testJoinAggregateFoldsChildrenIntoOneCell(): void
    {
        $this->seedTwoUsersWithOrders();

        $columns = [
            Column::make('id', 'ID'),
            Column::make('name', 'Name'),
            Column::make('orders', 'SKUs')->join(', '),
        ];

        $csv = $this->exportToCsv(new FlexibleSchema($this->makeRequest(), $columns));

        self::assertStringContainsString('"SKU-1-1, SKU-1-2, SKU-1-3"', $csv);
        self::assertStringContainsString('SKU-2-1', $csv);
    }

    /**
     * It auto-derives the count and sum onto the query without loading the
     * relation per item (the no-N+1 derivation).
     *
     * The source applies withCount()/withSum(), so each model carries the
     * orders_count and orders_sum_total aliases the column reads back, yet the
     * orders relation itself is never loaded - the aggregate came from the
     * query, not from materialising and counting children in PHP.
     *
     * @return void
     */
    public function testAggregatesAreDerivedOnTheQueryWithoutLoadingTheRelation(): void
    {
        $this->seedTwoUsersWithOrders();

        $source = (new QueryChunkSource(User::query(), 1000))
            ->withRelations([])
            ->withAggregates(new EagerLoadPlan(
                ['orders'],
                [['relation' => 'orders', 'column' => 'total']],
            ));

        $derived = [];

        foreach ($source->rows() as $user) {
            self::assertInstanceOf(User::class, $user);
            self::assertFalse($user->relationLoaded('orders'), 'The relation must not be materialised for an aggregate.');

            $derived[$user->id] = [$user->getAttribute('orders_count'), $user->getAttribute('orders_sum_total')];
        }

        self::assertEquals([1 => [3, 60], 2 => [1, 5]], $derived);
    }

    /**
     * It keeps the query count constant as the row count grows (no N+1).
     *
     * The count aggregate is a single derived sub-select, so streaming three
     * users costs the same number of queries as streaming nine; an N+1 would
     * scale the query count with the rows.
     *
     * @return void
     */
    public function testAggregateQueryCountDoesNotScaleWithRows(): void
    {
        $this->seedUsers(3);

        for ($id = 1; $id <= 3; $id++) {
            $this->seedOrders($id, [10, 20]);
        }

        $small = $this->countQueriesFor($this->countSchema());

        User::query()->delete(); // @phpstan-ignore staticMethod.dynamicCall
        Order::query()->delete(); // @phpstan-ignore staticMethod.dynamicCall

        $this->seedUsers(9);

        for ($id = 1; $id <= 9; $id++) {
            $this->seedOrders($id, [10, 20]);
        }

        $large = $this->countQueriesFor($this->countSchema());

        self::assertSame($small, $large, 'The query count must not scale with the number of rows.');
        self::assertLessThanOrEqual(2, $small);
    }

    /**
     * Seed two users with a known set of orders.
     *
     * @return void
     */
    private function seedTwoUsersWithOrders(): void
    {
        $this->seedUsers(2);
        $this->seedOrders(1, [10, 20, 30]);
        $this->seedOrders(2, [5]);
    }

    /**
     * Build a schema whose third column counts the orders relation.
     *
     * @return \SineMacula\Exporter\Schema\TabularSchema
     */
    private function countSchema(): TabularSchema
    {
        return new FlexibleSchema($this->makeRequest(), [
            Column::make('id', 'ID'),
            Column::make('name', 'Name'),
            Column::make('orders', 'Orders')->count(),
        ]);
    }

    /**
     * Build a schema whose third column sums the orders relation totals.
     *
     * @return \SineMacula\Exporter\Schema\TabularSchema
     */
    private function sumSchema(): TabularSchema
    {
        return new FlexibleSchema($this->makeRequest(), [
            Column::make('id', 'ID'),
            Column::make('name', 'Name'),
            Column::make('orders', 'Spend')->sum('total')->number(0),
        ]);
    }

    /**
     * Export the schema over the full users query as CSV.
     *
     * @param  \SineMacula\Exporter\Schema\TabularSchema  $schema
     * @return string
     */
    private function exportToCsv(TabularSchema $schema): string
    {
        $sink = new StringSink;

        (new Engine)->export(new QueryChunkSource(User::query()), $schema, $this->makeRequest(), new CsvWriter, $sink);

        return $sink->contents();
    }

    /**
     * Export the schema over the full users query as XLSX and read it back.
     *
     * @param  \SineMacula\Exporter\Schema\TabularSchema  $schema
     * @return list<list<mixed>>
     */
    private function exportToXlsx(TabularSchema $schema): array
    {
        $sink = new StringSink;

        (new Engine)->export(new QueryChunkSource(User::query()), $schema, $this->makeRequest(), new XlsxWriter, $sink);

        return $this->readWorkbookFromString($sink->contents());
    }

    /**
     * Count the queries an export of the given schema issues.
     *
     * @param  \SineMacula\Exporter\Schema\TabularSchema  $schema
     * @return int
     */
    private function countQueriesFor(TabularSchema $schema): int
    {
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        $this->exportToCsv($schema);

        $count = count(DB::connection()->getQueryLog());

        DB::connection()->disableQueryLog();

        return $count;
    }

    /**
     * Build the request the schema and engine are run for.
     *
     * @return \Illuminate\Http\Request
     */
    private function makeRequest(): Request
    {
        return Request::create('/');
    }
}
