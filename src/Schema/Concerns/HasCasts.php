<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Schema\Concerns;

use SineMacula\Exporter\Schema\CellValue;
use SineMacula\Exporter\Schema\Contracts\CastRegistry;
use SineMacula\Exporter\Schema\Enums\CellType;

/**
 * Column casting concern.
 *
 * The fluent typed-cast vocabulary and the cast half of the per-cell pipeline:
 * each sugar method records a named caster and its options, and the pipeline
 * either resolves that caster through the registry or infers a native type when
 * no cast is declared. Kept off the column class to keep its surface focused.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait HasCasts
{
    /** @var string|null The cast name resolved through the registry */
    private ?string $castName = null;

    /** @var array<string, mixed> The options passed to the cast */
    private array $castOptions = [];

    /**
     * Cast the value as a date.
     *
     * @param  string  $format
     * @param  string|null  $timezone
     * @return static
     */
    public function date(string $format = 'Y-m-d', ?string $timezone = null): static
    {
        return $this->castAs('date', ['format' => $format, 'timezone' => $timezone]);
    }

    /**
     * Cast the value as a date-time.
     *
     * @return static
     */
    public function dateTime(): static
    {
        return $this->castAs('datetime');
    }

    /**
     * Cast the value as a number with the given decimal precision.
     *
     * @param  int  $decimals
     * @return static
     */
    public function number(int $decimals = 0): static
    {
        return $this->castAs('number', ['decimals' => $decimals]);
    }

    /**
     * Cast the value as a boolean with the given true/false labels.
     *
     * @param  string  $true
     * @param  string  $false
     * @return static
     */
    public function boolean(string $true = 'Yes', string $false = 'No'): static
    {
        return $this->castAs('boolean', ['true' => $true, 'false' => $false]);
    }

    /**
     * Cast an enum value by its "value" (BackedEnum) or "name" (UnitEnum).
     *
     * @param  string  $by
     * @return static
     */
    public function enum(string $by = 'value'): static
    {
        return $this->castAs('enum', ['by' => $by]);
    }

    /**
     * Cast the value as a string.
     *
     * @return static
     */
    public function string(): static
    {
        return $this->castAs('string');
    }

    /**
     * Cast the value using a named caster from the registry.
     *
     * @param  string  $caster
     * @return static
     */
    public function cast(string $caster): static
    {
        return $this->castAs($caster);
    }

    /**
     * Record the cast name and options for the column.
     *
     * @param  string  $name
     * @param  array<string, mixed>  $options
     * @return static
     */
    private function castAs(string $name, array $options = []): static
    {
        $this->castName    = $name;
        $this->castOptions = $options;

        return $this;
    }

    /**
     * Cast the raw value into a typed cell, inferring the type when no cast is
     * declared.
     *
     * @param  mixed  $raw
     * @param  \SineMacula\Exporter\Schema\Contracts\CastRegistry  $registry
     * @return \SineMacula\Exporter\Schema\CellValue
     */
    private function castValue(mixed $raw, CastRegistry $registry): CellValue
    {
        if ($this->castName !== null) {
            return $registry->resolve($this->castName)->cast($raw, $this->castOptions);
        }

        return $this->inferCell($raw);
    }

    /**
     * Infer a typed cell from a native value when no cast is declared.
     *
     * @param  mixed  $raw
     * @return \SineMacula\Exporter\Schema\CellValue
     */
    private function inferCell(mixed $raw): CellValue
    {
        return match (true) {
            is_bool($raw)                      => new CellValue($raw, CellType::BOOLEAN),
            is_int($raw)                       => new CellValue($raw, CellType::INTEGER),
            is_float($raw)                     => new CellValue($raw, CellType::FLOAT),
            is_string($raw)                    => new CellValue($raw, CellType::STRING),
            $raw instanceof \DateTimeInterface => new CellValue(\DateTimeImmutable::createFromInterface($raw), CellType::DATE_TIME, 'Y-m-d H:i:s'),
            $raw instanceof \BackedEnum        => new CellValue($raw->value, is_int($raw->value) ? CellType::INTEGER : CellType::STRING),
            $raw instanceof \Stringable        => new CellValue((string) $raw, CellType::STRING),
            default                            => new CellValue('', CellType::STRING),
        };
    }
}
