<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Schema\CastRegistry;
use SineMacula\Exporter\Schema\CellValue;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\Enums\CellType;
use Tests\Support\V3\Enums\Role;

/**
 * Tests for the per-cell pipeline of a tabular column.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Column::class)]
#[CoversClass(CellValue::class)]
final class ColumnTest extends TestCase
{
    /** @var \SineMacula\Exporter\Schema\CastRegistry The shared cast registry */
    private CastRegistry $registry;

    /** @var \Illuminate\Http\Request The request passed through the pipeline */
    private Request $request;

    /**
     * Build the shared registry and request.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new CastRegistry;
        $this->request  = Request::create('/');
    }

    /**
     * It exposes the key and explicit heading.
     *
     * @return void
     */
    public function testExposesKeyAndHeading(): void
    {
        $column = Column::make('country.name', 'Country');

        self::assertSame('country.name', $column->getKey());
        self::assertSame('Country', $column->getHeading());
        self::assertNull(Column::make('id')->getHeading());
    }

    /**
     * It resolves a scalar value through the default accessor and infers type.
     *
     * @return void
     */
    public function testInfersNativeTypesFromTheDefaultAccessor(): void
    {
        self::assertCell(CellType::INTEGER, 7, $this->cell(Column::make('age'), ['age' => 7]));
        self::assertCell(CellType::FLOAT, 1.5, $this->cell(Column::make('rate'), ['rate' => 1.5]));
        self::assertCell(CellType::BOOLEAN, true, $this->cell(Column::make('on'), ['on' => true]));
        self::assertCell(CellType::STRING, 'hi', $this->cell(Column::make('msg'), ['msg' => 'hi']));
    }

    /**
     * It casts a value as a date carried natively with a format hint.
     *
     * @return void
     */
    public function testDateCastCarriesAnImmutableDateAndFormat(): void
    {
        $cell = $this->cell(Column::make('d')->date('Y-m-d'), ['d' => '2026-06-27 13:45:00']);

        self::assertSame(CellType::DATE, $cell->type);
        self::assertSame('Y-m-d', $cell->format);
        self::assertInstanceOf(\DateTimeImmutable::class, $cell->raw);
        self::assertSame('2026-06-27', $cell->raw->format('Y-m-d'));
    }

    /**
     * It applies the requested timezone to a timestamp date.
     *
     * @return void
     */
    public function testDateCastAppliesTimezoneToTimestamp(): void
    {
        $cell = $this->cell(Column::make('d')->date('Y-m-d', 'UTC'), ['d' => 1700000000]);

        self::assertInstanceOf(\DateTimeImmutable::class, $cell->raw);
        self::assertSame('2023-11-14', $cell->raw->format('Y-m-d'));
    }

    /**
     * It rounds a number to the requested precision and types the cell.
     *
     * @return void
     */
    public function testNumberCastRoundsAndTypes(): void
    {
        $float = $this->cell(Column::make('n')->number(2), ['n' => '3.14159']);

        self::assertSame(CellType::FLOAT, $float->type);
        self::assertSame(3.14, $float->raw);
        self::assertSame('0.00', $float->format);

        $integer = $this->cell(Column::make('n')->number(), ['n' => 3.7]);

        self::assertSame(CellType::INTEGER, $integer->type);
        self::assertSame(4, $integer->raw);
    }

    /**
     * It casts a boolean and keeps the labels as a format hint.
     *
     * @return void
     */
    public function testBooleanCastKeepsLabelsAsHint(): void
    {
        $cell = $this->cell(Column::make('b')->boolean('On', 'Off'), ['b' => 1]);

        self::assertSame(CellType::BOOLEAN, $cell->type);
        self::assertTrue($cell->raw);
        self::assertSame('On|Off', $cell->format);
    }

    /**
     * It casts an enum by value and by name.
     *
     * @return void
     */
    public function testEnumCastByValueAndName(): void
    {
        self::assertCell(CellType::STRING, 'admin', $this->cell(Column::make('r')->enum(), ['r' => Role::ADMIN]));
        self::assertCell(CellType::STRING, 'ADMIN', $this->cell(Column::make('r')->enum('name'), ['r' => Role::ADMIN]));
    }

    /**
     * It casts a value to a string.
     *
     * @return void
     */
    public function testStringCast(): void
    {
        self::assertCell(CellType::STRING, '123', $this->cell(Column::make('s')->string(), ['s' => 123]));
    }

    /**
     * It renders the default for a null value and a null cell otherwise.
     *
     * @return void
     */
    public function testNullAndDefaultPolicy(): void
    {
        self::assertCell(CellType::STRING, '-', $this->cell(Column::make('x')->default('-'), ['x' => null]));

        $nullCell = $this->cell(Column::make('x'), ['x' => null]);

        self::assertSame(CellType::NULL, $nullCell->type);
        self::assertNull($nullCell->raw);
    }

    /**
     * It uses an explicit resolver as the value source.
     *
     * @return void
     */
    public function testResolveUsingOverridesTheValueSource(): void
    {
        $column = Column::make('x')->resolveUsing(static fn (mixed $item, Request $request): string => 'computed');

        self::assertCell(CellType::STRING, 'computed', $this->cell($column, ['x' => 'raw']));
    }

    /**
     * It runs the formatter after the cast as a display transform.
     *
     * @return void
     */
    public function testFormatUsingRunsAfterCast(): void
    {
        $column = Column::make('x')->formatUsing(static fn (mixed $value, Request $request): string => is_string($value) ? strtoupper($value) : '');

        self::assertCell(CellType::STRING, 'ABC', $this->cell($column, ['x' => 'abc']));
    }

    /**
     * It falls back to the null policy when the formatter returns null.
     *
     * @return void
     */
    public function testFormatUsingNullFallsBackToDefault(): void
    {
        $column = Column::make('x')
            ->default('n/a')
            ->formatUsing(static fn (mixed $value, Request $request): ?string => null);

        self::assertCell(CellType::STRING, 'n/a', $this->cell($column, ['x' => 'abc']));
    }

    /**
     * It reads a raw model path when fromModel opts out of the accessor.
     *
     * @return void
     */
    public function testFromModelReadsTheRawPath(): void
    {
        $column = Column::make('display')->fromModel('profile.name');

        self::assertTrue($column->usesModelSource());
        self::assertSame('profile.name', $column->getModelPath());
        self::assertCell(CellType::STRING, 'Ada', $this->cell($column, ['profile' => ['name' => 'Ada']]));
    }

    /**
     * It resolves the count aggregate from the auto-derived key.
     *
     * @return void
     */
    public function testCountAggregate(): void
    {
        self::assertCell(CellType::INTEGER, 5, $this->cell(Column::make('orders')->count(), ['orders_count' => 5]));
    }

    /**
     * It resolves the sum aggregate from the auto-derived key.
     *
     * @return void
     */
    public function testSumAggregate(): void
    {
        self::assertCell(CellType::INTEGER, 42, $this->cell(Column::make('orders')->sum('total'), ['orders_sum_total' => 42]));
    }

    /**
     * It joins the scalar children of a has-many relation.
     *
     * @return void
     */
    public function testJoinAggregate(): void
    {
        self::assertCell(CellType::STRING, 'a / b / c', $this->cell(Column::make('tags')->join(' / '), ['tags' => ['a', 'b', 'c']]));
    }

    /**
     * It marks the single row-expansion axis.
     *
     * @return void
     */
    public function testExpandRowsMarksTheAxis(): void
    {
        self::assertFalse(Column::make('orders')->isExpanded());
        self::assertTrue(Column::make('orders')->expandRows()->isExpanded());
    }

    /**
     * It evaluates the request-aware visibility gate once.
     *
     * @return void
     */
    public function testVisibilityGate(): void
    {
        self::assertTrue(Column::make('x')->isVisible($this->request));

        $gated = Column::make('x')->visible(static fn (Request $request): bool => $request->boolean('admin'));

        self::assertFalse($gated->isVisible(Request::create('/')));
        self::assertTrue($gated->isVisible(Request::create('/?admin=1')));
    }

    /**
     * Assert a cell carries the expected type and raw value.
     *
     * @param  \SineMacula\Exporter\Schema\Enums\CellType  $type
     * @param  mixed  $raw
     * @param  \SineMacula\Exporter\Schema\CellValue  $cell
     * @return void
     */
    private static function assertCell(CellType $type, mixed $raw, CellValue $cell): void
    {
        self::assertSame($type, $cell->type);
        self::assertSame($raw, $cell->raw);
    }

    /**
     * Run the per-cell pipeline for the given column and item.
     *
     * @param  \SineMacula\Exporter\Schema\Column  $column
     * @param  array<string, mixed>  $item
     * @return \SineMacula\Exporter\Schema\CellValue
     */
    private function cell(Column $column, array $item): CellValue
    {
        return $column->toCellValue($item, $this->request, $this->registry);
    }
}
