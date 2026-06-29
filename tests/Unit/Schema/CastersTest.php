<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Schema\Casters\BooleanCaster;
use SineMacula\Exporter\Schema\Casters\DateCaster;
use SineMacula\Exporter\Schema\Casters\EnumCaster;
use SineMacula\Exporter\Schema\Casters\NumberCaster;
use SineMacula\Exporter\Schema\Enums\CellType;
use Tests\Support\Enums\Role;
use Tests\Support\Enums\Suit;

/**
 * Tests the built-in typed-cell casters.
 *
 * Each caster turns a raw value into a typed CellValue carrying a native value,
 * a cell type and a writer format hint. These tests drive the edges every
 * caster guards - unparseable input, timezone and format options, the integer
 * versus float number split, the enum name/value strategies and the non-backed
 * enum error - so a single schema can drive both the textual and typed writers.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DateCaster::class)]
#[CoversClass(NumberCaster::class)]
#[CoversClass(EnumCaster::class)]
#[CoversClass(BooleanCaster::class)]
final class CastersTest extends TestCase
{
    /**
     * It casts a DateTimeInterface into a date cell carrying the format hint.
     *
     * @return void
     */
    public function testDateCasterAcceptsADateTimeInstance(): void
    {
        $cell = (new DateCaster)->cast(new \DateTime('2026-01-02 03:04:05'));

        self::assertSame(CellType::DATE, $cell->type);
        self::assertInstanceOf(\DateTimeImmutable::class, $cell->raw);
        self::assertSame('2026-01-02', $cell->raw->format('Y-m-d'));
        self::assertSame('Y-m-d', $cell->format);
    }

    /**
     * It parses a UNIX timestamp from both an integer and a digit string.
     *
     * @return void
     */
    public function testDateCasterParsesTimestamps(): void
    {
        $fromInt    = (new DateCaster)->cast(0);
        $fromString = (new DateCaster)->cast('0');

        self::assertInstanceOf(\DateTimeImmutable::class, $fromInt->raw);
        self::assertInstanceOf(\DateTimeImmutable::class, $fromString->raw);
        self::assertSame(0, $fromInt->raw->getTimestamp());
        self::assertSame(0, $fromString->raw->getTimestamp());
    }

    /**
     * It applies a timezone option and falls back when the format is not text.
     *
     * @return void
     */
    public function testDateCasterAppliesTimezoneAndFormatFallback(): void
    {
        $cell = (new DateCaster(CellType::DATE_TIME, 'Y-m-d H:i:s'))->cast(
            '2026-01-01 00:00:00',
            ['timezone' => 'Europe/London', 'format' => 123],
        );

        self::assertInstanceOf(\DateTimeImmutable::class, $cell->raw);
        self::assertSame('Europe/London', $cell->raw->getTimezone()->getName());
        self::assertSame('Y-m-d H:i:s', $cell->format, 'A non-string format must fall back to the default.');
    }

    /**
     * It renders a date through an explicit string format option.
     *
     * The supplied pattern (not the caster's default) is carried as the format
     * hint, proving the option wins over the default.
     *
     * @return void
     */
    public function testDateCasterUsesAStringFormatOption(): void
    {
        $cell = (new DateCaster)->cast('2026-01-02 03:04:05', ['format' => 'd/m/Y']);

        self::assertSame('d/m/Y', $cell->format, 'The supplied format option must win over the default.');
    }

    /**
     * It yields a null cell for an unparseable or empty date string.
     *
     * @return void
     */
    public function testDateCasterReturnsNullForUnparseableValues(): void
    {
        self::assertSame(CellType::NULL, (new DateCaster)->cast('not a date at all')->type);
        self::assertSame(CellType::NULL, (new DateCaster)->cast('')->type);
        self::assertSame(CellType::NULL, (new DateCaster)->cast(null)->type);
    }

    /**
     * It yields a null cell for a non-numeric value.
     *
     * @return void
     */
    public function testNumberCasterRejectsNonNumericValues(): void
    {
        self::assertSame(CellType::NULL, (new NumberCaster)->cast('abc')->type);
    }

    /**
     * It emits an integer cell at zero decimals and a float cell otherwise.
     *
     * @return void
     */
    public function testNumberCasterSplitsIntegerAndFloat(): void
    {
        $integer = (new NumberCaster)->cast('41.6');
        $float   = (new NumberCaster)->cast('41.555', ['decimals' => 2]);

        self::assertSame(CellType::INTEGER, $integer->type);
        self::assertSame(42, $integer->raw);
        self::assertSame('0', $integer->format);

        self::assertSame(CellType::FLOAT, $float->type);
        self::assertSame(41.56, $float->raw);
        self::assertSame('0.00', $float->format);

        $roundedDown = (new NumberCaster)->cast('2.2');

        self::assertSame(2, $roundedDown->raw, 'A fractional value below the half must round to nearest, not ceil up.');
    }

    /**
     * It coerces a numeric-string decimals option into an integer precision.
     *
     * The precision is cast to int before it reaches round()/str_repeat(), so a
     * numeric string drives the same float precision an integer would.
     *
     * @return void
     */
    public function testNumberCasterCoercesNumericStringDecimals(): void
    {
        $cell = (new NumberCaster)->cast('7.891', ['decimals' => '2']);

        self::assertSame(CellType::FLOAT, $cell->type);
        self::assertSame(7.89, $cell->raw);
        self::assertSame('0.00', $cell->format);
    }

    /**
     * It extracts an enum by name and value, and passes scalars through.
     *
     * @return void
     */
    public function testEnumCasterStrategies(): void
    {
        self::assertSame('admin', (new EnumCaster)->cast(Role::ADMIN)->raw);
        self::assertSame('ADMIN', (new EnumCaster)->cast(Role::ADMIN, ['by' => 'name'])->raw);
        self::assertSame(7, (new EnumCaster)->cast(7)->raw);
        self::assertSame(CellType::INTEGER, (new EnumCaster)->cast(7)->type);
    }

    /**
     * It yields a null cell when the value has no scalar representation.
     *
     * @return void
     */
    public function testEnumCasterReturnsNullForNonScalarValues(): void
    {
        self::assertSame(CellType::NULL, (new EnumCaster)->cast(1.5)->type);
    }

    /**
     * It throws when asked to cast a non-backed enum by value.
     *
     * @return void
     */
    public function testEnumCasterRejectsNonBackedEnumByValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new EnumCaster)->cast(Suit::HEARTS);
    }

    /**
     * It coerces a non-boolean value and carries the label pair as the hint.
     *
     * @return void
     */
    public function testBooleanCasterCoercesAndCarriesLabels(): void
    {
        $cell = (new BooleanCaster)->cast('yes', ['true' => 'On', 'false' => 'Off']);

        self::assertSame(CellType::BOOLEAN, $cell->type);
        self::assertTrue($cell->raw);
        self::assertSame('On|Off', $cell->format);
        self::assertFalse((new BooleanCaster)->cast(0)->raw);
        self::assertTrue((new BooleanCaster)->cast(true)->raw, 'A native boolean passes through unchanged.');
    }
}
