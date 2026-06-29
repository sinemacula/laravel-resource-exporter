<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Schema\Casters\BooleanCaster;
use SineMacula\Exporter\Schema\Casters\DateCaster;
use SineMacula\Exporter\Schema\Casters\EnumCaster;
use SineMacula\Exporter\Schema\Casters\NumberCaster;
use SineMacula\Exporter\Schema\Casters\StringCaster;
use SineMacula\Exporter\Schema\CastRegistry;
use SineMacula\Exporter\Schema\CellValue;
use SineMacula\Exporter\Schema\Contracts\Caster;
use SineMacula\Exporter\Schema\Enums\CellType;
use Tests\Support\Enums\Role;
use Tests\Support\Enums\Suit;

/**
 * Tests for the single cast registry and the built-in casters.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CastRegistry::class)]
#[CoversClass(BooleanCaster::class)]
#[CoversClass(DateCaster::class)]
#[CoversClass(EnumCaster::class)]
#[CoversClass(NumberCaster::class)]
#[CoversClass(StringCaster::class)]
#[CoversClass(CellValue::class)]
final class CastRegistryTest extends TestCase
{
    /**
     * It seeds the built-in casters by name.
     *
     * @return void
     */
    public function testSeedsBuiltInCasters(): void
    {
        $registry = new CastRegistry;

        foreach (['date', 'datetime', 'number', 'boolean', 'enum', 'string'] as $name) {
            self::assertTrue($registry->has($name), "Expected caster [{$name}] to be registered.");
        }
    }

    /**
     * It registers and resolves a custom caster.
     *
     * @return void
     */
    public function testRegistersAndResolvesCustomCaster(): void
    {
        $registry = new CastRegistry;

        $caster = new class implements Caster {
            /**
             * Cast a raw value into a typed cell value.
             *
             * @param  mixed  $value
             * @param  array<string, mixed>  $options
             * @return \SineMacula\Exporter\Schema\CellValue
             */
            #[\Override]
            public function cast(mixed $value, array $options = []): CellValue
            {
                return new CellValue('shouted', CellType::STRING);
            }
        };

        self::assertSame($registry, $registry->register('shout', $caster));
        self::assertTrue($registry->has('shout'));
        self::assertSame($caster, $registry->resolve('shout'));
    }

    /**
     * It throws when resolving an unknown caster.
     *
     * @return void
     */
    public function testResolveThrowsForUnknownCaster(): void
    {
        $registry = new CastRegistry;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No caster is registered under [missing].');

        $registry->resolve('missing');
    }

    /**
     * It returns a null cell for an unparseable date.
     *
     * @return void
     */
    public function testDateCasterReturnsNullForUnparseableValue(): void
    {
        $cell = (new DateCaster)->cast('not-a-date');

        self::assertSame(CellType::NULL, $cell->type);
        self::assertNull($cell->raw);
    }

    /**
     * It returns a null cell for a non-numeric value.
     *
     * @return void
     */
    public function testNumberCasterReturnsNullForNonNumeric(): void
    {
        $cell = (new NumberCaster)->cast('abc');

        self::assertSame(CellType::NULL, $cell->type);
    }

    /**
     * It coerces falsy strings to a native boolean with default labels.
     *
     * @return void
     */
    public function testBooleanCasterDefaultsAndCoercion(): void
    {
        $cell = (new BooleanCaster)->cast('false');

        self::assertSame(CellType::BOOLEAN, $cell->type);
        self::assertFalse($cell->raw);
        self::assertSame('Yes|No', $cell->format);
    }

    /**
     * It throws when casting a non-backed enum by value.
     *
     * @return void
     */
    public function testEnumCasterThrowsForUnitEnumByValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot cast a non-backed enum by value; use the "name" strategy.');

        (new EnumCaster)->cast(Suit::HEARTS, ['by' => 'value']);
    }

    /**
     * It casts a non-backed enum by name.
     *
     * @return void
     */
    public function testEnumCasterCastsUnitEnumByName(): void
    {
        $cell = (new EnumCaster)->cast(Suit::HEARTS, ['by' => 'name']);

        self::assertSame('HEARTS', $cell->raw);
    }

    /**
     * It passes a scalar through the enum caster unchanged.
     *
     * @return void
     */
    public function testEnumCasterPassesScalarThrough(): void
    {
        self::assertSame(5, (new EnumCaster)->cast(5)->raw);
        self::assertSame(CellType::INTEGER, (new EnumCaster)->cast(5)->type);
    }

    /**
     * It stringifies a backed enum and blanks an opaque value.
     *
     * @return void
     */
    public function testStringCasterHandlesEnumsAndOpaqueValues(): void
    {
        self::assertSame('admin', (new StringCaster)->cast(Role::ADMIN)->raw);
        self::assertSame(CellType::NULL, (new StringCaster)->cast(null)->type);

        $opaque = (new StringCaster)->cast(['array']);

        self::assertSame('', $opaque->raw);
        self::assertSame(CellType::STRING, $opaque->type);
    }
}
