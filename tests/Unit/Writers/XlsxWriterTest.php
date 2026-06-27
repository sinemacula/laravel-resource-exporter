<?php

declare(strict_types = 1);

namespace Tests\Unit\Writers;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Exceptions\MissingXlsxDependency;
use SineMacula\Exporter\Exceptions\XlsxRowLimitExceeded;
use SineMacula\Exporter\Schema\CellValue;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\Enums\CellType;
use SineMacula\Exporter\Sinks\StringSink;
use SineMacula\Exporter\Sinks\TempFileSink;
use SineMacula\Exporter\Writers\XlsxWriter;
use Tests\Support\V3\Concerns\ReadsXlsx;
use Tests\Support\V3\Concerns\ShapesRows;
use Tests\Support\V3\Schema\ArraySchema;

/**
 * Golden read-back tests for the tabular XLSX writer.
 *
 * Each test drives the writer with shaped, typed-cell rows and reads the
 * generated workbook back through the OpenSpout reader, so the assertions are
 * against the real spreadsheet a consumer opens - including the native cell
 * types (numbers as numeric cells, dates as date cells) that are the XLSX
 * advantage over CSV. Both the seekable (in-memory string) and non-seekable
 * (temp-file, finalise-then-putFromFile) sink halves are exercised.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(XlsxWriter::class)]
final class XlsxWriterTest extends TestCase
{
    use ReadsXlsx;
    use ShapesRows;

    /**
     * It emits a heading row and typed cells - integers as numbers, decimals as
     * floats, dates as native date cells, booleans/strings as text - through a
     * seekable sink.
     *
     * @return void
     */
    public function testGoldenReadBackEmitsHeadingAndTypedCells(): void
    {
        $request = Request::create('/');
        $columns = [
            Column::make('id', 'ID'),
            Column::make('name', 'Name'),
            Column::make('note', 'Note'),
            Column::make('score', 'Score')->number(2),
            Column::make('active', 'Active')->boolean('Yes', 'No'),
            Column::make('joined', 'Joined')->date('Y-m-d'),
        ];

        $rows = $this->export($columns, $request, [
            ['id' => 1, 'name' => 'Alice', 'note' => 'héllo', 'score' => 12.5, 'active' => true, 'joined' => new \DateTimeImmutable('2026-01-02')],
            ['id' => 2, 'name' => 'Bob', 'note' => null, 'score' => 7.25, 'active' => false, 'joined' => new \DateTimeImmutable('2026-03-04')],
        ]);

        self::assertCount(3, $rows, 'Expected a heading row and two data rows.');
        self::assertSame(['ID', 'Name', 'Note', 'Score', 'Active', 'Joined'], $rows[0]);

        [$id, $name, $note, $score, $active, $joined] = $rows[1];

        self::assertIsInt($id);
        self::assertSame(1, $id);
        self::assertSame('Alice', $name);
        self::assertSame('héllo', $note);
        self::assertIsFloat($score);
        self::assertSame(12.5, $score);
        self::assertSame('Yes', $active);
        self::assertInstanceOf(\DateTimeInterface::class, $joined);
        self::assertSame('2026-01-02', $joined->format('Y-m-d'));

        // The second row proves the null cell renders empty and the boolean
        // false label is emitted.
        self::assertSame('', $rows[2][2]);
        self::assertSame('No', $rows[2][4]);
        self::assertInstanceOf(\DateTimeInterface::class, $rows[2][5]);
    }

    /**
     * It produces a valid, readable workbook through a non-seekable sink, via
     * the finalise-then-putFromFile path.
     *
     * @return void
     */
    public function testNonSeekableSinkProducesReadableWorkbook(): void
    {
        $request = Request::create('/');
        $columns = [Column::make('id', 'ID'), Column::make('name', 'Name')];
        $schema  = new ArraySchema($request, $columns);
        $rows    = $this->shapeRows([['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']], $columns, $request);

        $sink = new TempFileSink;
        (new XlsxWriter)->write($rows, $schema, $sink);

        $read = $this->readWorkbook($sink->path());

        self::assertSame(['ID', 'Name'], $read[0]);
        self::assertSame([1, 'Alice'], $read[1]);
        self::assertSame([2, 'Bob'], $read[2]);

        @unlink($sink->path());
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
            Column::make('int_ok', 'A'),
            Column::make('int_bad', 'B'),
            Column::make('float_ok', 'C'),
            Column::make('float_bad', 'D'),
            Column::make('bool', 'E'),
            Column::make('scalar', 'F'),
            Column::make('stringable', 'G'),
            Column::make('array', 'H'),
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
            'int_ok'     => new CellValue('5', CellType::INTEGER),
            'int_bad'    => new CellValue('x', CellType::INTEGER),
            'float_ok'   => new CellValue('1.5', CellType::FLOAT),
            'float_bad'  => new CellValue('x', CellType::FLOAT),
            'bool'       => new CellValue(true, CellType::BOOLEAN),
            'scalar'     => new CellValue(12, CellType::STRING),
            'stringable' => new CellValue($stringable, CellType::STRING),
            'array'      => new CellValue(['x'], CellType::STRING),
        ];

        $sink = new StringSink;
        (new XlsxWriter)->write([$row], new ArraySchema($request, $columns, headings: false), $sink);

        $read = $this->readWorkbookFromString($sink->contents());

        self::assertEqualsWithDelta(5, $read[0][0], 0.0);
        self::assertEqualsWithDelta(0, $read[0][1], 0.0);
        self::assertEqualsWithDelta(1.5, $read[0][2], 0.0);
        self::assertEqualsWithDelta(0.0, $read[0][3], 0.0);
        self::assertSame('Yes', $read[0][4]);
        self::assertSame('12', $read[0][5]);
        self::assertSame('as-string', $read[0][6]);
        self::assertEmpty($read[0][7]);
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

        (new XlsxWriter)->write([$row], new ArraySchema($request, $columns, headings: false), $sink);

        self::assertSame('2026-06-27', $this->readWorkbookFromString($sink->contents())[0][0]);
    }

    /**
     * It omits the heading row when the schema disables headings.
     *
     * @return void
     */
    public function testHeadingsCanBeDisabled(): void
    {
        $request = Request::create('/');
        $columns = [Column::make('id', 'ID'), Column::make('name', 'Name')];
        $schema  = new ArraySchema($request, $columns, headings: false);
        $rows    = $this->shapeRows([['id' => 1, 'name' => 'Alice']], $columns, $request);

        $sink = new StringSink;
        (new XlsxWriter)->write($rows, $schema, $sink);

        $read = $this->readWorkbookFromString($sink->contents());

        self::assertCount(1, $read);
        self::assertSame([1, 'Alice'], $read[0]);
    }

    /**
     * It writes an empty, readable workbook for an empty row set.
     *
     * @return void
     */
    public function testEmptySetProducesEmptyWorkbook(): void
    {
        $request = Request::create('/');
        $columns = [Column::make('id', 'ID'), Column::make('name', 'Name')];
        $schema  = new ArraySchema($request, $columns);

        $sink = new StringSink;
        (new XlsxWriter)->write([], $schema, $sink);

        self::assertSame([], $this->readWorkbookFromString($sink->contents()));
    }

    /**
     * It reports the XLSX vendor media type.
     *
     * @return void
     */
    public function testMediaType(): void
    {
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            (new XlsxWriter)->mediaType(),
        );
    }

    /**
     * It throws once a worksheet would exceed the XLSX hard sheet cap.
     *
     * The cap is the XLSX format's fixed 1,048,576-row limit with no injection
     * seam, and the writer is final, so the guard is asserted at its boundary
     * (the row at the cap is accepted, the row past it throws) rather than by
     * generating a million rows.
     *
     * @return void
     */
    public function testRowCountGuardThrowsPastTheSheetCap(): void
    {
        $writer = new XlsxWriter;
        $guard  = new \ReflectionMethod($writer, 'guardRowCount');
        $limit  = (new \ReflectionClass($writer))->getConstant('MAX_ROWS_PER_SHEET');

        self::assertSame(1048576, $limit);
        self::assertSame($limit, $guard->invoke($writer, $limit));

        $this->expectException(XlsxRowLimitExceeded::class);
        $this->expectExceptionMessage('1048576');

        $guard->invoke($writer, $limit + 1);
    }

    /**
     * It surfaces an actionable exception describing the missing OpenSpout
     * dependency.
     *
     * The class_exists guard cannot be exercised end to end while
     * openspout/openspout is installed as a dev dependency (class_exists is
     * always true under the suite), so the exception the guard raises - the
     * observable contract a consumer without the package sees - is asserted
     * directly.
     *
     * @return void
     */
    public function testMissingDependencyExceptionIsActionable(): void
    {
        $exception = MissingXlsxDependency::create();

        self::assertStringContainsString('openspout/openspout', $exception->getMessage());
        self::assertStringContainsString('composer require openspout/openspout', $exception->getMessage());
    }

    /**
     * Export the given items through the writer and read the workbook back.
     *
     * @param  list<\SineMacula\Exporter\Schema\Column>  $columns
     * @param  \Illuminate\Http\Request  $request
     * @param  list<array<string, mixed>>  $items
     * @return list<list<mixed>>
     */
    private function export(array $columns, Request $request, array $items): array
    {
        $schema = new ArraySchema($request, $columns);
        $rows   = $this->shapeRows($items, $columns, $request);
        $sink   = new StringSink;

        (new XlsxWriter)->write($rows, $schema, $sink);

        return $this->readWorkbookFromString($sink->contents());
    }
}
