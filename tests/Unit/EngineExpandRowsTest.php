<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Engine;
use SineMacula\Exporter\Exceptions\InvalidExportSchema;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\Enums\Strictness;
use SineMacula\Exporter\Schema\ExpandAxis;
use SineMacula\Exporter\Schema\ExpandPolicy;
use SineMacula\Exporter\Schema\TabularSchema;
use SineMacula\Exporter\Schema\WarningCollector;
use SineMacula\Exporter\Sinks\StringSink;
use SineMacula\Exporter\Writers\CsvWriter;
use Tests\Support\V3\ArraySource;
use Tests\Support\V3\Schema\FlexibleSchema;

/**
 * Tests for the engine's single row-expansion axis.
 *
 * A column marked expandRows() against a declared expand policy fans one parent
 * into one row per child, repeating the parent columns; a childless parent
 * keeps a single blank-child row by default or is dropped when configured.
 * Exactly one axis governs the fan-out (the schema exposes a single
 * ExpandPolicy), and a marked column with no declared axis is a configuration
 * error that preflight rejects before any bytes and lenient downgrades to a
 * warning.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Engine::class)]
#[CoversClass(ExpandAxis::class)]
#[CoversClass(ExpandPolicy::class)]
#[CoversClass(WarningCollector::class)]
final class EngineExpandRowsTest extends TestCase
{
    /**
     * A parent with two children yields two rows, parent cells repeated.
     *
     * @return void
     */
    public function testParentWithTwoChildrenYieldsTwoRows(): void
    {
        $item = ['id' => 1, 'name' => 'Ada', 'items' => [['sku' => 'A'], ['sku' => 'B']]];

        $csv = $this->expandToCsv([$item], new ExpandPolicy('items'));

        self::assertSame("ID,Name,SKU\n1,Ada,A\n1,Ada,B\n", $csv);
    }

    /**
     * Multiple expanded columns all resolve against the single declared axis.
     *
     * @return void
     */
    public function testMultipleExpandedColumnsShareTheSingleAxis(): void
    {
        $item = ['id' => 1, 'name' => 'Ada', 'items' => [['sku' => 'A', 'qty' => 2], ['sku' => 'B', 'qty' => 5]]];

        $columns = [
            Column::make('id', 'ID'),
            Column::make('name', 'Name'),
            Column::make('sku', 'SKU')->expandRows(),
            Column::make('qty', 'Qty')->expandRows(),
        ];

        $csv = $this->export([$item], new FlexibleSchema(self::request(), $columns, expand: new ExpandPolicy('items')));

        self::assertSame("ID,Name,SKU,Qty\n1,Ada,A,2\n1,Ada,B,5\n", $csv);
    }

    /**
     * A childless parent keeps one blank-child row by default.
     *
     * @return void
     */
    public function testChildlessParentKeepsOneBlankRowByDefault(): void
    {
        $item = ['id' => 2, 'name' => 'Bob', 'items' => []];

        $csv = $this->expandToCsv([$item], new ExpandPolicy('items'));

        self::assertSame("ID,Name,SKU\n2,Bob,\n", $csv);
    }

    /**
     * A childless parent is dropped entirely when the policy drops empties.
     *
     * The single parent produces no rows, so the writer - which emits the
     * heading only alongside a first data row - yields an empty export.
     *
     * @return void
     */
    public function testChildlessParentIsDroppedWhenConfigured(): void
    {
        $item = ['id' => 2, 'name' => 'Bob', 'items' => []];

        $csv = $this->expandToCsv([$item], new ExpandPolicy('items', dropWhenEmpty: true));

        self::assertSame('', $csv);
    }

    /**
     * A dropped parent is still skipped while a sibling with children expands.
     *
     * @return void
     */
    public function testDroppedParentIsSkippedWhileSiblingExpands(): void
    {
        $items = [
            ['id' => 1, 'name' => 'Ada', 'items' => [['sku' => 'A']]],
            ['id' => 2, 'name' => 'Bob', 'items' => []],
        ];

        $csv = $this->expandToCsv($items, new ExpandPolicy('items', dropWhenEmpty: true));

        self::assertSame("ID,Name,SKU\n1,Ada,A\n", $csv);
    }

    /**
     * An expand column with no declared axis fails preflight before any bytes.
     *
     * A second expand axis cannot be declared (the schema exposes one
     * ExpandPolicy), so the realisable misconfiguration is an expandRows()
     * column with no axis behind it; preflight rejects it before the writer
     * emits anything.
     *
     * @return void
     */
    public function testMarkedColumnWithoutAxisFailsPreflightBeforeAnyBytes(): void
    {
        $columns = [Column::make('id', 'ID'), Column::make('sku', 'SKU')->expandRows()];
        $schema  = new FlexibleSchema(self::request(), $columns);
        $sink    = new StringSink;

        try {
            (new Engine)->export(new ArraySource([['id' => 1]]), $schema, self::request(), new CsvWriter, $sink);
            self::fail('Expected an invalid-schema exception for an expand column with no axis.');
        } catch (InvalidExportSchema $exception) {
            self::assertStringContainsString('expandRows()', $exception->getMessage());
        }

        self::assertSame('', $sink->contents(), 'No bytes may be written when preflight rejects the schema.');
    }

    /**
     * A lenient schema disables the unbacked expansion and records a warning.
     *
     * @return void
     */
    public function testLenientDisablesUnbackedExpansionWithAWarning(): void
    {
        $columns  = [Column::make('id', 'ID'), Column::make('sku', 'SKU')->expandRows()];
        $schema   = new FlexibleSchema(self::request(), $columns, strictness: Strictness::LENIENT);
        $sink     = new StringSink;
        $warnings = new WarningCollector;

        (new Engine)->export(new ArraySource([['id' => 1, 'sku' => 'A']]), $schema, self::request(), new CsvWriter, $sink, $warnings);

        self::assertSame("ID,SKU\n1,A\n", $sink->contents());
        self::assertNotEmpty($warnings->all());
        self::assertStringContainsString('Row expansion disabled', $warnings->all()[0]);
    }

    /**
     * A lenient schema disables an expand policy with an empty relation.
     *
     * @return void
     */
    public function testLenientDisablesAnEmptyExpandRelationWithAWarning(): void
    {
        $columns  = [Column::make('id', 'ID'), Column::make('sku', 'SKU')];
        $schema   = new FlexibleSchema(self::request(), $columns, strictness: Strictness::LENIENT, expand: new ExpandPolicy(''));
        $sink     = new StringSink;
        $warnings = new WarningCollector;

        (new Engine)->export(new ArraySource([['id' => 1, 'sku' => 'A']]), $schema, self::request(), new CsvWriter, $sink, $warnings);

        self::assertSame("ID,SKU\n1,A\n", $sink->contents());
        self::assertStringContainsString('empty relation', $warnings->all()[0]);
    }

    /**
     * Preflight rejects a whitespace-only expand relation as empty.
     *
     * The relation is trimmed before the emptiness test, so a relation of only
     * spaces fails preflight before any bytes are written rather than streaming
     * an export against a blank relation.
     *
     * @return void
     */
    public function testPreflightRejectsAWhitespaceOnlyExpandRelation(): void
    {
        $columns = [Column::make('id', 'ID'), Column::make('sku', 'SKU')];
        $schema  = new FlexibleSchema(self::request(), $columns, expand: new ExpandPolicy('   '));
        $sink    = new StringSink;

        try {
            (new Engine)->export(new ArraySource([['id' => 1, 'sku' => 'A']]), $schema, self::request(), new CsvWriter, $sink);
            self::fail('Expected preflight to reject the whitespace-only expand relation.');
        } catch (InvalidExportSchema $exception) {
            self::assertStringContainsString('empty relation', $exception->getMessage());
        }

        self::assertSame('', $sink->contents(), 'No bytes may be written when preflight rejects the schema.');
    }

    /**
     * A lenient empty-relation policy disables expansion entirely.
     *
     * The disabled axis must leave the marked column rendering from the parent
     * rather than fanning a blank child row, so the value survives unblanked.
     *
     * @return void
     */
    public function testLenientEmptyRelationLeavesTheMarkedColumnRenderingFromTheParent(): void
    {
        $columns  = [Column::make('id', 'ID'), Column::make('sku', 'SKU')->expandRows()];
        $schema   = new FlexibleSchema(self::request(), $columns, strictness: Strictness::LENIENT, expand: new ExpandPolicy(''));
        $sink     = new StringSink;
        $warnings = new WarningCollector;

        (new Engine)->export(new ArraySource([['id' => 1, 'sku' => 'A']]), $schema, self::request(), new CsvWriter, $sink, $warnings);

        self::assertSame("ID,SKU\n1,A\n", $sink->contents());
    }

    /**
     * Preflight propagates an expanded child cell resolver failure.
     *
     * Under preflight a throwing child resolver is not blanked but raised, so
     * the export fails fast rather than degrading the row.
     *
     * @return void
     */
    public function testPreflightPropagatesAThrowingExpandedChildCell(): void
    {
        $columns = [
            Column::make('id', 'ID'),
            Column::make('sku', 'SKU')->expandRows()->resolveUsing(static function (): string {
                throw new \RuntimeException('child kaboom');
            }),
        ];
        $schema = new FlexibleSchema(self::request(), $columns, expand: new ExpandPolicy('items'));

        $item = ['id' => 1, 'items' => [['x' => 1]]];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('child kaboom');

        (new Engine)->export(new ArraySource([$item]), $schema, self::request(), new CsvWriter, new StringSink);
    }

    /**
     * A lenient schema blanks an expanded child cell whose resolver throws.
     *
     * @return void
     */
    public function testLenientBlanksAThrowingExpandedChildCell(): void
    {
        $columns = [
            Column::make('id', 'ID'),
            Column::make('sku', 'SKU')->expandRows()->resolveUsing(static function (): string {
                throw new \RuntimeException('child kaboom');
            }),
        ];
        $schema   = new FlexibleSchema(self::request(), $columns, strictness: Strictness::LENIENT, expand: new ExpandPolicy('items'));
        $sink     = new StringSink;
        $warnings = new WarningCollector;

        $item = ['id' => 1, 'items' => [['x' => 1], ['x' => 2]]];

        (new Engine)->export(new ArraySource([$item]), $schema, self::request(), new CsvWriter, $sink, $warnings);

        self::assertSame("ID,SKU\n1,\n1,\n", $sink->contents());
        self::assertStringContainsString('child kaboom', $warnings->all()[0]);
    }

    /**
     * A lazily-iterated child generator expands to the same rows as an array.
     *
     * The engine iterates the axis children directly rather than buffering them
     * into an intermediate list, so a parent whose children arrive as a lazy
     * Generator fans out identically - proving the expansion stays
     * constant-memory on the child axis without changing the output.
     *
     * @return void
     */
    public function testGeneratorChildrenExpandToTheSameRowsAsAnArray(): void
    {
        $children = static function (): \Generator {
            yield ['sku' => 'A'];
            yield ['sku' => 'B'];
            yield ['sku' => 'C'];
        };

        $item = ['id' => 1, 'name' => 'Ada', 'items' => $children()];

        $csv = $this->expandToCsv([$item], new ExpandPolicy('items'));

        self::assertSame("ID,Name,SKU\n1,Ada,A\n1,Ada,B\n1,Ada,C\n", $csv);
    }

    /**
     * A childless lazy generator still yields the single blank-child row.
     *
     * @return void
     */
    public function testEmptyGeneratorChildrenKeepTheBlankRowDefault(): void
    {
        $children = static function (): \Generator {
            yield from [];
        };

        $item = ['id' => 2, 'name' => 'Bob', 'items' => $children()];

        $csv = $this->expandToCsv([$item], new ExpandPolicy('items'));

        self::assertSame("ID,Name,SKU\n2,Bob,\n", $csv);
    }

    /**
     * Export the items through an expanded SKU schema and return the CSV.
     *
     * @param  list<mixed>  $items
     * @param  \SineMacula\Exporter\Schema\ExpandPolicy  $policy
     * @return string
     */
    private function expandToCsv(array $items, ExpandPolicy $policy): string
    {
        $columns = [
            Column::make('id', 'ID'),
            Column::make('name', 'Name'),
            Column::make('sku', 'SKU')->expandRows(),
        ];

        return $this->export($items, new FlexibleSchema(self::request(), $columns, expand: $policy));
    }

    /**
     * Export the items through the schema and return the CSV string.
     *
     * @param  list<mixed>  $items
     * @param  \SineMacula\Exporter\Schema\TabularSchema  $schema
     * @return string
     */
    private function export(array $items, TabularSchema $schema): string
    {
        $sink = new StringSink;

        (new Engine)->export(new ArraySource($items), $schema, self::request(), new CsvWriter, $sink);

        return $sink->contents();
    }

    /**
     * Build a bare request for the engine run.
     *
     * @return \Illuminate\Http\Request
     */
    private static function request(): Request
    {
        return Request::create('/');
    }
}
