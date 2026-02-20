<?php

declare(strict_types = 1);

namespace Tests\Support;

use SineMacula\Exporter\ExporterServiceProvider;

/**
 * Service provider harness for deterministic publishing branch tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class ExporterServiceProviderHarness extends ExporterServiceProvider
{
    /** @var bool */
    private bool $configPathFunctionAvailable = true;

    /** @var string */
    private string $resolvedConfigPath = '/virtual/config/exporter.php';

    /**
     * Set whether config_path helper is available.
     *
     * @param  bool  $available
     * @return void
     */
    public function setConfigPathFunctionAvailable(bool $available): void
    {
        $this->configPathFunctionAvailable = $available;
    }

    /**
     * Set the resolved config path value for publish mapping.
     *
     * @param  string  $path
     * @return void
     */
    public function setResolvedConfigPath(string $path): void
    {
        $this->resolvedConfigPath = $path;
    }

    /**
     * Resolve config publish path with deterministic test value.
     *
     * @param  string  $path
     * @return string
     */
    #[\Override]
    protected function resolveConfigPath(string $path): string
    {
        return $this->resolvedConfigPath;
    }

    /**
     * Determine whether config_path helper is available.
     *
     * @return bool
     */
    #[\Override]
    protected function hasConfigPathFunction(): bool
    {
        return $this->configPathFunctionAvailable;
    }
}
