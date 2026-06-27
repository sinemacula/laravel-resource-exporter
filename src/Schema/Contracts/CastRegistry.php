<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Schema\Contracts;

/**
 * Cast registry contract.
 *
 * The single registry mapping cast names to casters. Typed column casts and
 * the `cast()` escape hatch both resolve through here, giving one place to
 * register, override, and test casting behaviour.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface CastRegistry
{
    /**
     * Register a caster under the given name.
     *
     * @param  string  $name
     * @param  \SineMacula\Exporter\Schema\Contracts\Caster  $caster
     * @return static
     */
    public function register(string $name, Caster $caster): static;

    /**
     * Determine whether a caster is registered under the given name.
     *
     * @param  string  $name
     * @return bool
     */
    public function has(string $name): bool;

    /**
     * Resolve the caster registered under the given name.
     *
     * @param  string  $name
     * @return \SineMacula\Exporter\Schema\Contracts\Caster
     *
     * @throws \InvalidArgumentException
     */
    public function resolve(string $name): Caster;
}
