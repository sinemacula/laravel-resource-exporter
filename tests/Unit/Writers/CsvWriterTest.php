<?php

declare(strict_types = 1);

namespace Tests\Unit\Writers;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Sinks\StringSink;
use SineMacula\Exporter\Writers\CsvWriter;
use Tests\Support\V3\Concerns\ShapesRows;
use Tests\Support\V3\Schema\ArraySchema;

/**
 * Golden-output tests for the streaming CSV writer.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CsvWriter::class)]
final class CsvWriterTest extends TestCase
{
    use ShapesRows;

    /**
     * It emits the heading row and renders null, boolean, Unicode and quoted
     * fields exactly.
     *
     * @return void
     */
    public function testGoldenOutputWithHeadingNullsBooleansAndUnicode(): void
    {
        $output = $this->write(new CsvWriter, [
            ['id' => 1, 'name' => 'Alice', 'active' => true, 'note' => 'héllo'],
            ['id' => 2, 'name' => 'Bob, Jr', 'active' => false, 'note' => null],
        ]);

        self::assertSame(
            "ID,Name,Active,Note\n"
            . "1,Alice,Yes,héllo\n"
            . "2,\"Bob, Jr\",No,\n",
            $output,
        );
    }

    /**
     * It prepends the UTF-8 byte-order mark when enabled.
     *
     * @return void
     */
    public function testBomToggle(): void
    {
        $rows = [['id' => 1, 'name' => 'A', 'active' => true, 'note' => 'x']];

        $withBom    = $this->write(new CsvWriter(bom: true), $rows);
        $withoutBom = $this->write(new CsvWriter, $rows);

        self::assertStringStartsWith("\xEF\xBB\xBF", $withBom);
        self::assertStringStartsNotWith("\xEF\xBB\xBF", $withoutBom);
        self::assertSame("\xEF\xBB\xBF" . $withoutBom, $withBom);
    }

    /**
     * It neutralises spreadsheet formula-injection triggers by default.
     *
     * @return void
     */
    public function testFormulaInjectionEscapedByDefault(): void
    {
        $rows = [
            ['id' => 1, 'name' => '=cmd', 'active' => true, 'note' => '+1'],
            ['id' => 2, 'name' => '-2', 'active' => true, 'note' => '@x'],
        ];

        $output = $this->write(new CsvWriter, $rows);

        self::assertStringContainsString('\'=cmd', $output);
        self::assertStringContainsString('\'+1', $output);
        self::assertStringContainsString('\'-2', $output);
        self::assertStringContainsString('\'@x', $output);
    }

    /**
     * It leaves formula triggers untouched when escaping is disabled.
     *
     * @return void
     */
    public function testFormulaEscapingCanBeDisabled(): void
    {
        $output = $this->write(new CsvWriter(escapeFormula: false), [
            ['id' => 1, 'name' => '=cmd', 'active' => true, 'note' => '+1'],
        ]);

        self::assertStringContainsString('=cmd', $output);
        self::assertStringNotContainsString('\'=cmd', $output);
    }

    /**
     * It omits the heading row when the schema disables headings.
     *
     * @return void
     */
    public function testHeadingsCanBeDisabled(): void
    {
        $request = Request::create('/');
        $columns = [Column::make('id'), Column::make('name')];
        $schema  = new ArraySchema($request, $columns, headings: false);
        $rows    = $this->shapeRows([['id' => 1, 'name' => 'A']], $columns, $request);

        $sink = new StringSink;
        (new CsvWriter)->write($rows, $schema, $sink);

        self::assertSame("1,A\n", $sink->contents());
    }

    /**
     * It writes nothing for an empty row set.
     *
     * @return void
     */
    public function testEmptySetProducesNoOutput(): void
    {
        self::assertSame('', $this->write(new CsvWriter, []));
    }

    /**
     * It reports the CSV media type.
     *
     * @return void
     */
    public function testMediaType(): void
    {
        self::assertSame('text/csv', (new CsvWriter)->mediaType());
    }

    /**
     * Write the given items through the writer and return the buffered output.
     *
     * @param  \SineMacula\Exporter\Writers\CsvWriter  $writer
     * @param  list<array<string, mixed>>  $items
     * @return string
     */
    private function write(CsvWriter $writer, array $items): string
    {
        $request = Request::create('/');
        $columns = [
            Column::make('id', 'ID'),
            Column::make('name', 'Name'),
            Column::make('active', 'Active')->boolean('Yes', 'No'),
            Column::make('note', 'Note'),
        ];

        $schema = new ArraySchema($request, $columns);
        $rows   = $this->shapeRows($items, $columns, $request);
        $sink   = new StringSink;

        $writer->write($rows, $schema, $sink);

        return $sink->contents();
    }
}
