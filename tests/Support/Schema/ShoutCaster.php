<?php

declare(strict_types = 1);

namespace Tests\Support\Schema;

use SineMacula\Exporter\Schema\CellValue;
use SineMacula\Exporter\Schema\Contracts\Caster;
use SineMacula\Exporter\Schema\Enums\CellType;

/**
 * Test caster that upper-cases its value.
 *
 * Exists to prove a caster registered on the shared cast registry is reachable
 * to an export through the container-wired engine.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final readonly class ShoutCaster implements Caster
{
    /**
     * Cast a raw value into an upper-cased string cell.
     *
     * @param  mixed  $value
     * @param  array<string, mixed>  $options
     * @return \SineMacula\Exporter\Schema\CellValue
     */
    #[\Override]
    public function cast(mixed $value, array $options = []): CellValue
    {
        $text = is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';

        return new CellValue(strtoupper($text), CellType::STRING);
    }
}
