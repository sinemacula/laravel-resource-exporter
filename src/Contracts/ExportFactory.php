<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Contracts;

/**
 * Export factory contract.
 *
 * The public surface of the export manager, bound in the container under this
 * interface so the documented `app(...)->extend(...)` and constructor injection
 * resolve the same singleton. Implemented additively by the existing manager;
 * v2 behaviour is unchanged.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface ExportFactory
{
    /**
     * Get an exporter instance for the given driver name.
     *
     * @param  string|null  $name
     * @return \SineMacula\Exporter\Contracts\Exporter
     */
    public function format(?string $name = null): Exporter;

    /**
     * Build an on-demand exporter from the given configuration.
     *
     * @param  array<string, mixed>|null  $config
     * @return \SineMacula\Exporter\Contracts\Exporter
     */
    public function build(?array $config = null): Exporter;

    /**
     * Register a custom driver creator Closure.
     *
     * @param  string  $driver
     * @param  \Closure(\Illuminate\Contracts\Foundation\Application, array<string, mixed>): \SineMacula\Exporter\Contracts\Exporter  $callback
     * @return self
     */
    public function extend(string $driver, \Closure $callback): self;
}
