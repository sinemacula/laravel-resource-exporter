<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Schema\Casters;

use SineMacula\Exporter\Schema\CellValue;
use SineMacula\Exporter\Schema\Contracts\Caster;
use SineMacula\Exporter\Schema\Enums\CellType;

/**
 * Number caster.
 *
 * Rounds a numeric value to the requested precision and carries it natively on
 * the cell, with a zero-padded number mask as the format hint. Zero decimals
 * yields an integer cell; a positive precision yields a float cell, so a typed
 * writer can emit a native numeric cell and a textual writer can render the
 * value against the mask.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class NumberCaster implements Caster
{
    /**
     * Cast a raw value into a typed number cell.
     *
     * @param  mixed  $value
     * @param  array<string, mixed>  $options
     * @return \SineMacula\Exporter\Schema\CellValue
     */
    #[\Override]
    public function cast(mixed $value, array $options = []): CellValue
    {
        if (!is_numeric($value)) {
            return new CellValue(null, CellType::NULL);
        }

        $decimals = isset($options['decimals']) && is_numeric($options['decimals'])
            ? (int) $options['decimals']
            : 0;

        if ($decimals > 0) {
            return new CellValue(round((float) $value, $decimals), CellType::FLOAT, '0.' . str_repeat('0', $decimals));
        }

        return new CellValue((int) round((float) $value), CellType::INTEGER, '0');
    }
}
