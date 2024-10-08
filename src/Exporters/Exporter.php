<?php

namespace SineMacula\Exporter\Exporters;

/**
 * The base exporter driver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
abstract class Exporter
{
    /** @var array<string, mixed> The exporter configuration */
    protected array $config;

    /** @var array<int, string> The fields to ignore in the export */
    protected array $ignored = [];

    /**
     * Create an exporter driver instance.
     *
     * @param  array  $config
     */
    public function __construct(array $config)
    {
        $this->setConfig([
            ...$this->getDefaultConfig(),
            ...$config
        ]);
    }

    /**
     * Get the exporter configuration options.
     *
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Ensure the export does not contain any of the given fields.
     *
     * @param  string|array  $fields
     * @return static
     */
    public function withoutFields(string|array $fields): static
    {
        $this->ignored = is_array($fields) ? $fields : [$fields];

        return $this;
    }

    /**
     * Get the default exporter configuration options.
     *
     * @return array
     */
    protected function getDefaultConfig(): array
    {
        return defined(static::class . '::DEFAULT_CONFIG')
            ? static::DEFAULT_CONFIG
            : [];
    }

    /**
     * Check if a value is stringable (can be safely converted to a string).
     *
     * @param  mixed  $value
     * @return bool
     */
    protected function isStringable(mixed $value): bool
    {
        return is_scalar($value) || is_null($value) || (is_object($value) && method_exists($value, '__toString'));
    }

    /**
     * Sets the configuration for the exporter.
     *
     * @param  array  $config
     * @return void
     */
    private function setConfig(array $config): void
    {
        $this->config = $config;
    }
}
