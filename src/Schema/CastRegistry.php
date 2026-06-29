<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Schema;

use SineMacula\Exporter\Schema\Casters\BooleanCaster;
use SineMacula\Exporter\Schema\Casters\DateCaster;
use SineMacula\Exporter\Schema\Casters\EnumCaster;
use SineMacula\Exporter\Schema\Casters\NumberCaster;
use SineMacula\Exporter\Schema\Casters\StringCaster;
use SineMacula\Exporter\Schema\Contracts\Caster;
use SineMacula\Exporter\Schema\Contracts\CastRegistry as CastRegistryContract;
use SineMacula\Exporter\Schema\Enums\CellType;

/**
 * Default cast registry.
 *
 * The single registry every typed column cast and the `cast()` escape hatch
 * resolve through, so there is one place to register, override, and test
 * casting behaviour. Seeded with the built-in casters on construction and held
 * with no per-request state, it is safe to share across exports.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class CastRegistry implements CastRegistryContract
{
    /** @var array<string, \SineMacula\Exporter\Schema\Contracts\Caster> The registered casters keyed by name */
    private array $casters = [];

    /**
     * Create a new cast registry seeded with the built-in casters.
     */
    public function __construct()
    {
        $this->register('date', new DateCaster(CellType::DATE, 'Y-m-d'));
        $this->register('datetime', new DateCaster(CellType::DATE_TIME, 'Y-m-d H:i:s'));
        $this->register('number', new NumberCaster);
        $this->register('boolean', new BooleanCaster);
        $this->register('enum', new EnumCaster);
        $this->register('string', new StringCaster);
    }

    /**
     * Register a caster under the given name.
     *
     * @param  string  $name
     * @param  \SineMacula\Exporter\Schema\Contracts\Caster  $caster
     * @return static
     */
    #[\Override]
    public function register(string $name, Caster $caster): static
    {
        $this->casters[$name] = $caster;

        return $this;
    }

    /**
     * Determine whether a caster is registered under the given name.
     *
     * @param  string  $name
     * @return bool
     */
    #[\Override]
    public function has(string $name): bool
    {
        return isset($this->casters[$name]);
    }

    /**
     * Resolve the caster registered under the given name.
     *
     * @param  string  $name
     * @return \SineMacula\Exporter\Schema\Contracts\Caster
     *
     * @throws \InvalidArgumentException
     */
    #[\Override]
    public function resolve(string $name): Caster
    {
        return $this->casters[$name]
            ?? throw new \InvalidArgumentException("No caster is registered under [{$name}].");
    }
}
