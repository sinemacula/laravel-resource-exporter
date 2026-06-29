<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Schema\Casters;

use SineMacula\Exporter\Schema\CellValue;
use SineMacula\Exporter\Schema\Contracts\Caster;
use SineMacula\Exporter\Schema\Enums\CellType;

/**
 * String caster.
 *
 * Coerces a value to its string representation. Scalars and Stringables
 * stringify directly, a backed enum stringifies its value, and anything else
 * (an array or an opaque object) yields an empty cell rather than an unreadable
 * dump - a has-many should be aggregated or expanded, not stringified.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class StringCaster implements Caster
{
    /**
     * Cast a raw value into a string cell.
     *
     * @param  mixed  $value
     * @param  array<string, mixed>  $options
     * @return \SineMacula\Exporter\Schema\CellValue
     */
    #[\Override]
    public function cast(mixed $value, array $options = []): CellValue
    {
        if ($value === null) {
            return new CellValue(null, CellType::NULL);
        }

        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if (is_scalar($value) || $value instanceof \Stringable) {
            return new CellValue((string) $value, CellType::STRING);
        }

        return new CellValue('', CellType::STRING);
    }
}
