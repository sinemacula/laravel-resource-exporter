<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Schema\Contracts;

use SineMacula\Exporter\Schema\CellValue;

/**
 * Cell caster contract.
 *
 * Converts a raw resolved value into a typed CellValue. Each typed column cast
 * (date, number, boolean, enum, ...) is thin sugar over a caster resolved from
 * the single cast registry, so there is one resolution path and one test
 * surface. Casters are stateless and constructed per export.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface Caster
{
    /**
     * Cast a raw value into a typed cell value.
     *
     * @param  mixed  $value
     * @param  array<string, mixed>  $options
     * @return \SineMacula\Exporter\Schema\CellValue
     */
    public function cast(mixed $value, array $options = []): CellValue;
}
