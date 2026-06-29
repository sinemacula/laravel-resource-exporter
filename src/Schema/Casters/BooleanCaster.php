<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Schema\Casters;

use SineMacula\Exporter\Schema\CellValue;
use SineMacula\Exporter\Schema\Contracts\Caster;
use SineMacula\Exporter\Schema\Enums\CellType;

/**
 * Boolean caster.
 *
 * Coerces a value to a native boolean carried on the cell, with the pair of
 * display labels kept as a pipe-delimited format hint (`"true|false"`). A typed
 * writer can emit a native boolean cell while a textual writer renders the
 * matching label, so one cast drives both representations.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class BooleanCaster implements Caster
{
    /**
     * Cast a raw value into a typed boolean cell.
     *
     * @param  mixed  $value
     * @param  array<string, mixed>  $options
     * @return \SineMacula\Exporter\Schema\CellValue
     */
    #[\Override]
    public function cast(mixed $value, array $options = []): CellValue
    {
        $true  = isset($options['true'])  && is_string($options['true']) ? $options['true'] : 'Yes';
        $false = isset($options['false']) && is_string($options['false']) ? $options['false'] : 'No';

        $bool = is_bool($value)
            ? $value
            : filter_var($value, FILTER_VALIDATE_BOOLEAN);

        return new CellValue($bool, CellType::BOOLEAN, $true . '|' . $false);
    }
}
