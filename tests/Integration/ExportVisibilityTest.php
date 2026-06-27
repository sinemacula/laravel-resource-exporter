<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Engine;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\TabularSchema;
use SineMacula\Exporter\Sinks\StringSink;
use SineMacula\Exporter\Sources\QueryChunkSource;
use SineMacula\Exporter\Writers\CsvWriter;
use Tests\Support\V3\ExporterTestCase;
use Tests\Support\V3\Models\HiddenAttributeUser;
use Tests\Support\V3\Models\User;
use Tests\Support\V3\Schema\FlexibleSchema;

/**
 * Tests for the column-level visibility gate, including the leak boundary.
 *
 * visible() is a build-time authorisation gate (fn(Request): bool, no item):
 * a column whose gate returns false is omitted entirely - from the heading row
 * and from every data row. The security property is that a gated-out column
 * cannot leak its value through any other path, including the aggregate
 * eager-derivation that runs over the same query.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Engine::class)]
#[CoversClass(Column::class)]
#[CoversClass(QueryChunkSource::class)]
final class ExportVisibilityTest extends ExporterTestCase
{
    /**
     * A gated-out column is absent from the heading row and every data row.
     *
     * @return void
     */
    public function testGatedOutColumnIsAbsentFromHeadingsAndRows(): void
    {
        $this->seedFixture();

        $csv = $this->exportToCsv($this->schemaGatedBy(false));

        self::assertStringStartsWith("ID,Name,Orders\n", $csv);
        self::assertStringContainsString('1,"User 1",2', $csv);
        self::assertStringNotContainsString('Secret', $csv);
        self::assertStringNotContainsString('secret-1', $csv);
    }

    /**
     * A gated-in column is present in the heading row and every data row.
     *
     * @return void
     */
    public function testGatedInColumnIsPresent(): void
    {
        $this->seedFixture();

        $csv = $this->exportToCsv($this->schemaGatedBy(true));

        self::assertStringStartsWith("ID,Name,Secret,Orders\n", $csv);
        self::assertStringContainsString('secret-1,2', $csv);
    }

    /**
     * A gated-out column cannot leak through the aggregate derivation path.
     *
     * The schema both gates out the secret column and declares a count
     * aggregate that rewrites the query; the gated value must still never reach
     * the output, proving the gate removes the column before any writer work.
     *
     * @return void
     */
    public function testGatedColumnCannotLeakViaTheAggregatePath(): void
    {
        $this->seedFixture();

        $csv = $this->exportToCsv($this->schemaGatedBy(false));

        self::assertStringContainsString('2', $csv, 'The count aggregate must still render.');
        self::assertStringNotContainsString('secret-1', $csv, 'The gated secret must not leak via the aggregate path.');
    }

    /**
     * A $hidden model attribute is still emitted in tabular output by default.
     *
     * Export value resolution reads the raw model attribute via data_get, which
     * does not consult the model's $hidden serialisation gating - so a value
     * the model hides from toArray()/toJson() still reaches the export. This is
     * the honest, intended behaviour (the D1 boundary): field gating in an
     * export is the schema author's job via ->visible(), not an inherited side
     * effect of the model or its resource.
     *
     * @return void
     */
    public function testHiddenModelAttributeIsStillEmittedByDefault(): void
    {
        $this->seedUsers(1);

        $model = HiddenAttributeUser::query()->firstOrFail();

        // The model hides the secret from its own array serialisation...
        self::assertArrayNotHasKey('secret', $model->toArray());

        // ...yet the raw-attribute tabular export still emits it by default.
        $columns = [
            Column::make('id', 'ID'),
            Column::make('secret', 'Secret'),
        ];

        $csv = $this->exportToCsv(new FlexibleSchema(Request::create('/'), $columns), HiddenAttributeUser::query());

        self::assertStringStartsWith("ID,Secret\n", $csv);
        self::assertStringContainsString('secret-1', $csv);
    }

    /**
     * Seed one user with two orders and a known secret.
     *
     * @return void
     */
    private function seedFixture(): void
    {
        $this->seedUsers(1);
        $this->seedOrders(1, [10, 20]);
    }

    /**
     * Build a schema whose secret column is gated by the given decision.
     *
     * @param  bool  $admin
     * @return \SineMacula\Exporter\Schema\TabularSchema
     */
    private function schemaGatedBy(bool $admin): TabularSchema
    {
        $columns = [
            Column::make('id', 'ID'),
            Column::make('name', 'Name'),
            Column::make('secret', 'Secret')->visible(static fn (Request $request): bool => $admin),
            Column::make('orders', 'Orders')->count(),
        ];

        return new FlexibleSchema(Request::create('/'), $columns);
    }

    /**
     * Export the schema over the given query (the full users query by default)
     * as CSV.
     *
     * @param  \SineMacula\Exporter\Schema\TabularSchema  $schema
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>|null  $query
     * @return string
     */
    private function exportToCsv(TabularSchema $schema, ?Builder $query = null): string
    {
        $sink = new StringSink;

        (new Engine)->export(new QueryChunkSource($query ?? User::query()), $schema, Request::create('/'), new CsvWriter, $sink);

        return $sink->contents();
    }
}
