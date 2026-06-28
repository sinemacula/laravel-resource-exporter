<?php

declare(strict_types = 1);

namespace Tests\Unit\Writers;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Schema\CellValue;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\Enums\CellType;
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
     * It honours a configured flush threshold.
     *
     * @return void
     */
    public function testFlushThresholdIsApplied(): void
    {
        $output = $this->write(new CsvWriter(flushThreshold: 1), [
            ['id' => 1, 'name' => 'A', 'active' => true, 'note' => 'x'],
        ]);

        self::assertSame("ID,Name,Active,Note\n1,A,Yes,x\n", $output);
    }

    /**
     * It renders every typed-cell edge: loose numbers, label fallbacks and
     * non-scalars.
     *
     * @return void
     */
    public function testRendersTypedCellEdgeCases(): void
    {
        $request = Request::create('/');
        $columns = [
            Column::make('int_str', 'A'),
            Column::make('int_bad', 'B'),
            Column::make('float_str', 'C'),
            Column::make('float_bad', 'D'),
            Column::make('bool_default', 'E'),
            Column::make('bool_false', 'F'),
            Column::make('scalar', 'G'),
            Column::make('stringable', 'H'),
            Column::make('array', 'I'),
        ];

        $stringable = new class implements \Stringable {
            /**
             * Render the throwaway value as a fixed string.
             *
             * @return string
             */
            #[\Override]
            public function __toString(): string
            {
                return 'as-string';
            }
        };

        $row = [
            'int_str'      => new CellValue('5', CellType::INTEGER),
            'int_bad'      => new CellValue('x', CellType::INTEGER),
            'float_str'    => new CellValue('1.5', CellType::FLOAT),
            'float_bad'    => new CellValue('x', CellType::FLOAT),
            'bool_default' => new CellValue(true, CellType::BOOLEAN),
            'bool_false'   => new CellValue(false, CellType::BOOLEAN, 'On|Off'),
            'scalar'       => new CellValue(12, CellType::STRING),
            'stringable'   => new CellValue($stringable, CellType::STRING),
            'array'        => new CellValue(['a'], CellType::STRING),
        ];

        $sink = new StringSink;
        (new CsvWriter(escapeFormula: false))->write([$row], new ArraySchema($request, $columns), $sink);

        $lines = explode("\n", $sink->contents());

        self::assertSame('5,0,1.5,0,Yes,Off,12,as-string,', $lines[1]);
    }

    /**
     * It renders a date cell whose raw value is not a date through its string.
     *
     * @return void
     */
    public function testRendersANonDateRawForADateCell(): void
    {
        $request = Request::create('/');
        $columns = [Column::make('day', 'Day')];

        $row  = ['day' => new CellValue('2026-06-27', CellType::DATE)];
        $sink = new StringSink;

        (new CsvWriter)->write([$row], new ArraySchema($request, $columns), $sink);

        self::assertSame("Day\n2026-06-27\n", $sink->contents());
    }

    /**
     * It honours the configured delimiter, enclosure and end-of-line bytes.
     *
     * @return void
     */
    public function testConfiguredDialectBytes(): void
    {
        $request = Request::create('/');
        $columns = [Column::make('a'), Column::make('b')];
        $schema  = new ArraySchema($request, $columns, headings: false);

        $rows = [
            ['a' => new CellValue('x', CellType::STRING), 'b' => new CellValue('p;q', CellType::STRING)],
            ['a' => new CellValue('y', CellType::STRING), 'b' => new CellValue('z', CellType::STRING)],
        ];

        $sink = new StringSink;
        (new CsvWriter(delimiter: ';', enclosure: '#', endOfLine: "\r\n", escapeFormula: false))->write($rows, $schema, $sink);

        self::assertSame("x;#p;q#\r\ny;z\r\n", $sink->contents());
    }

    /**
     * It applies RFC-4180 quote doubling - leaving a backslash field unenclosed
     * - rather than the league default backslash escape.
     *
     * @return void
     */
    public function testEmptyEscapeLeavesBackslashFieldsUnenclosed(): void
    {
        $request = Request::create('/');
        $columns = [Column::make('a')];
        $schema  = new ArraySchema($request, $columns, headings: false);

        $rows = [['a' => new CellValue('a\b', CellType::STRING)]];

        $sink = new StringSink;
        (new CsvWriter(escapeFormula: false))->write($rows, $schema, $sink);

        self::assertSame("a\\b\n", $sink->contents());
    }

    /**
     * It humanises a dotted column key into a spaced heading.
     *
     * @return void
     */
    public function testDottedKeyHeadingIsHumanised(): void
    {
        $request = Request::create('/');
        $columns = [Column::make('user.full_name')];
        $schema  = new ArraySchema($request, $columns);

        $rows = [['user.full_name' => new CellValue('v', CellType::STRING)]];

        $sink = new StringSink;
        (new CsvWriter(escapeFormula: false))->write($rows, $schema, $sink);

        // league/csv encloses the spaced heading; the dot must still become a
        // space (mutating away the str_replace yields "User.full Name").
        self::assertSame("\"User Full Name\"\nv\n", $sink->contents());
    }

    /**
     * It renders date and date-time cells through their distinct format hints.
     *
     * @return void
     */
    public function testRendersDateAndDateTimeCellsThroughTheirFormat(): void
    {
        $request = Request::create('/');
        $columns = [Column::make('d', 'D'), Column::make('dt', 'DT')];
        $schema  = new ArraySchema($request, $columns, headings: false);

        $moment = new \DateTimeImmutable('2026-06-27 13:45:30');
        $rows   = [[
            'd'  => new CellValue($moment, CellType::DATE, 'd/m/Y'),
            'dt' => new CellValue($moment, CellType::DATE_TIME, 'Y-m-d H:i:s'),
        ]];

        $sink = new StringSink;
        (new CsvWriter(escapeFormula: false))->write($rows, $schema, $sink);

        // league/csv encloses the spaced date-time field; the date arm must use
        // each cell's own format hint, not a forced 'Y-m-d'.
        self::assertSame("27/06/2026,\"2026-06-27 13:45:30\"\n", $sink->contents());
    }

    /**
     * It keeps later pipe-delimited boolean label segments intact for the false
     * label.
     *
     * @return void
     */
    public function testBooleanFalseLabelRetainsTrailingPipeSegments(): void
    {
        $request = Request::create('/');
        $columns = [Column::make('b', 'B')];
        $schema  = new ArraySchema($request, $columns, headings: false);

        $rows = [['b' => new CellValue(false, CellType::BOOLEAN, 'On|Off|Maybe')]];

        $sink = new StringSink;
        (new CsvWriter(escapeFormula: false))->write($rows, $schema, $sink);

        self::assertSame("Off|Maybe\n", $sink->contents());
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
