<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Schema\Casters;

use SineMacula\Exporter\Schema\CellValue;
use SineMacula\Exporter\Schema\Contracts\Caster;
use SineMacula\Exporter\Schema\Enums\CellType;

/**
 * Enum caster.
 *
 * Extracts a scalar from an enum by its backing value (`value`) or its case
 * name (`name`). A non-backed UnitEnum has no value, so requesting `value` for
 * one is a configuration error and throws. A value that is already scalar
 * passes through unchanged, so a pre-resolved column key still casts cleanly.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class EnumCaster implements Caster
{
    /**
     * Cast a raw value into a typed enum cell.
     *
     * @param  mixed  $value
     * @param  array<string, mixed>  $options
     * @return \SineMacula\Exporter\Schema\CellValue
     *
     * @throws \InvalidArgumentException
     */
    #[\Override]
    public function cast(mixed $value, array $options = []): CellValue
    {
        $by = isset($options['by']) && is_string($options['by']) ? $options['by'] : 'value';

        $scalar = $this->extract($value, $by);

        if ($scalar === null) {
            return new CellValue(null, CellType::NULL);
        }

        return is_int($scalar)
            ? new CellValue($scalar, CellType::INTEGER)
            : new CellValue($scalar, CellType::STRING);
    }

    /**
     * Extract the scalar representation of the given value.
     *
     * @param  mixed  $value
     * @param  string  $by
     * @return int|string|null
     *
     * @throws \InvalidArgumentException
     */
    private function extract(mixed $value, string $by): int|string|null
    {
        if ($value instanceof \UnitEnum) {
            return $this->fromEnum($value, $by);
        }

        return is_int($value) || is_string($value) ? $value : null;
    }

    /**
     * Extract the scalar representation of an enum case.
     *
     * @param  \UnitEnum  $enum
     * @param  string  $by
     * @return int|string
     *
     * @throws \InvalidArgumentException
     */
    private function fromEnum(\UnitEnum $enum, string $by): int|string
    {
        if ($by === 'name') {
            return $enum->name;
        }

        if (!$enum instanceof \BackedEnum) {
            throw new \InvalidArgumentException('Cannot cast a non-backed enum by value; use the "name" strategy.');
        }

        return $enum->value;
    }
}
