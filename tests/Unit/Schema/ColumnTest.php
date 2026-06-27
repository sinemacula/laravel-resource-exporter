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
     * It joins only the scalar/stringable children and skips the rest.
     *
     * @return void
     */
    public function testJoinAggregateSkipsNonScalarChildren(): void
    {
        $children = ['a', ['nested'], 'b', new \stdClass];

        self::assertCell(CellType::STRING, 'a, b', $this->cell(Column::make('tags')->join(), ['tags' => $children]));
        self::assertCell(CellType::STRING, '', $this->cell(Column::make('tags')->join(), ['tags' => 'not-iterable']));
    }

    /**
     * It coerces a non-numeric count aggregate to zero.
     *
     * @return void
     */
    public function testCountAggregateCoercesNonNumericToZero(): void
    {
        self::assertCell(CellType::INTEGER, 0, $this->cell(Column::make('orders')->count(), ['orders_count' => 'not-a-number']));
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
     * It casts a value as a date-time with the default pattern.
     *
     * @return void
     */
    public function testDateTimeCast(): void
    {
        $cell = $this->cell(Column::make('d')->dateTime(), ['d' => '2026-06-27 13:45:00']);

        self::assertSame(CellType::DATE_TIME, $cell->type);
        self::assertSame('Y-m-d H:i:s', $cell->format);
    }

    /**
     * It casts through a named caster resolved from the registry.
     *
     * @return void
     */
    public function testCastEscapeHatchResolvesANamedCaster(): void
    {
        $column = Column::make('n')->cast('number');

        self::assertSame('number', $column->getCastName());
        self::assertCell(CellType::INTEGER, 5, $this->cell($column, ['n' => '5']));
    }

    /**
     * It infers native cell types from uncast values, including the fallbacks.
     *
     * @return void
     */
    public function testInfersTheRemainingNativeTypes(): void
    {
        $dateTime = $this->cell(Column::make('d'), ['d' => new \DateTime('2026-01-02 03:04:05')]);
        self::assertSame(CellType::DATE_TIME, $dateTime->type);
        self::assertInstanceOf(\DateTimeImmutable::class, $dateTime->raw);

        self::assertCell(CellType::STRING, 'admin', $this->cell(Column::make('r'), ['r' => Role::ADMIN]));

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
        self::assertCell(CellType::STRING, 'as-string', $this->cell(Column::make('s'), ['s' => $stringable]));

        self::assertCell(CellType::STRING, '', $this->cell(Column::make('o'), ['o' => ['nested' => 1]]));
    }

    /**
     * It resolves an expansion child cell from the child by key and resolver.
     *
     * @return void
     */
    public function testChildCellResolvesFromTheChild(): void
    {
        self::assertCell(CellType::STRING, 'SKU-9', $this->childCell(Column::make('sku'), ['sku' => 'SKU-9']));

        $resolved = Column::make('label')->resolveUsing(static fn (mixed $child, Request $request): string => 'child-' . $child['sku']);
        self::assertCell(CellType::STRING, 'child-X', $this->childCell($resolved, ['sku' => 'X']));
    }

    /**
     * It formats an expansion child cell and blanks a null child.
     *
     * @return void
     */
    public function testChildCellFormatsAndBlanksNull(): void
    {
        $formatted = Column::make('sku')->formatUsing(static fn (mixed $value, Request $request): string => is_string($value) ? strtoupper($value) : '');
        self::assertCell(CellType::STRING, 'AB', $this->childCell($formatted, ['sku' => 'ab']));

        $blank = Column::make('sku')->toChildCellValue(null, $this->request, $this->registry);
        self::assertSame(CellType::NULL, $blank->type);
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
     * It reads a custom date format option back as the cell's format hint.
     *
     * @return void
     */
    public function testDateCastUsesACustomFormatHint(): void
    {
        $cell = $this->cell(Column::make('d')->date('d/m/Y'), ['d' => '2026-06-27 13:45:00']);

        self::assertSame(CellType::DATE, $cell->type);
        self::assertSame('d/m/Y', $cell->format, 'The declared date format must reach the caster as the hint.');
        self::assertInstanceOf(\DateTimeImmutable::class, $cell->raw);
    }

    /**
     * It coerces a numeric-string count aggregate to a native integer.
     *
     * @return void
     */
    public function testCountAggregateCoercesNumericStringToInt(): void
    {
        self::assertCell(CellType::INTEGER, 7, $this->cell(Column::make('orders')->count(), ['orders_count' => '7']));
    }

    /**
     * It normalises a dotted sum path into the underscored alias it reads back.
     *
     * @return void
     */
    public function testSumAggregateNormalisesADottedPath(): void
    {
        self::assertCell(CellType::INTEGER, 99, $this->cell(Column::make('orders')->sum('items.total'), ['orders_sum_items_total' => 99]));
    }

    /**
     * It reads an expansion child cell from the raw model path when opted out.
     *
     * @return void
     */
    public function testChildCellReadsTheRawModelPath(): void
    {
        $column = Column::make('sku')->fromModel('detail.code');

        self::assertCell(CellType::STRING, 'X', $this->childCell($column, ['detail' => ['code' => 'X'], 'sku' => 'wrong']));
    }

    /**
     * It stringifies a Stringable formatter result into a string cell.
     *
     * @return void
     */
    public function testFormatUsingStringableResultIsStringified(): void
    {
        $stringable = new class implements \Stringable {
            /**
             * Render the throwaway formatter result as a fixed string.
             *
             * @return string
             */
            #[\Override]
            public function __toString(): string
            {
                return 'STR';
            }
        };

        $column = Column::make('x')->formatUsing(static fn (mixed $value, Request $request): \Stringable => $stringable);

        self::assertCell(CellType::STRING, 'STR', $this->cell($column, ['x' => 'abc']));
    }

    /**
     * It renders an empty string for a non-scalar, non-Stringable result.
     *
     * @return void
     */
    public function testFormatUsingNonScalarResultBecomesEmptyString(): void
    {
        $column = Column::make('x')->formatUsing(static fn (mixed $value, Request $request): array => ['unrenderable']);

        self::assertCell(CellType::STRING, '', $this->cell($column, ['x' => 'abc']));
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

    /**
     * Run the per-cell pipeline for the column against an expansion child.
     *
     * @param  \SineMacula\Exporter\Schema\Column  $column
     * @param  array<string, mixed>  $child
     * @return \SineMacula\Exporter\Schema\CellValue
     */
    private function childCell(Column $column, array $child): CellValue
    {
        return $column->toChildCellValue($child, $this->request, $this->registry);
    }
}
