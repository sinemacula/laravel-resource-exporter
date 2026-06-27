<?php

declare(strict_types = 1);

namespace SineMacula\Exporter;

use Illuminate\Contracts\Foundation\Application;
use SineMacula\Exporter\Contracts\Exporter;
use SineMacula\Exporter\Contracts\ExportFactory;
use SineMacula\Exporter\Exporters\Csv;
use SineMacula\Exporter\Exporters\Xml;

/**
 * The export manager.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @mixin \SineMacula\Exporter\Contracts\Exporter
 */
final class ExportManager implements ExportFactory
{
    /** @var array<string, \SineMacula\Exporter\Contracts\Exporter> Resolved exporters. */
    private array $exporters = [];

    /** @var array<string, \Closure(\Illuminate\Contracts\Foundation\Application, array<string, mixed>): \SineMacula\Exporter\Contracts\Exporter> */
    private array $customCreators = [];

    /**
     * Create a new export manager instance.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     */
    public function __construct(

        /** The application instance */
        public Application $app, // phpcs:ignore SineMacula.Classes.RequireReadonlyPublicProperty.Mutable
    ) {}

    /**
     * Dynamically call the default driver instance.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $parameters
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    public function __call(string $method, array $parameters): mixed
    {
        $exporter = $this->format();

        if (!is_callable([$exporter, $method])) {
            throw new \InvalidArgumentException("Method [{$method}] is not supported.");
        }

        return call_user_func_array([$exporter, $method], $parameters);
    }

    /**
     * Get an export instance.
     *
     * @param  string|null  $name
     * @return \SineMacula\Exporter\Contracts\Exporter
     */
    #[\Override]
    public function format(?string $name = null): Exporter
    {
        $name ??= $this->getDefaultDriver();

        return $this->get($name);
    }

    /**
     * Build an on-demand exporter.
     *
     * @param  array<string, mixed>|null  $config
     * @return \SineMacula\Exporter\Contracts\Exporter
     */
    #[\Override]
    public function build(?array $config = null): Exporter
    {
        return $this->resolve('ondemand', $config ?? ['driver' => $this->getDefaultDriver()]);
    }

    /**
     * Create an instance of the CSV driver.
     *
     * @param  array<string, mixed>  $config
     * @return \SineMacula\Exporter\Contracts\Exporter
     */
    public function createCsvDriver(array $config): Exporter
    {
        return new Csv($config);
    }

    /**
     * Create an instance of the XML driver.
     *
     * @param  array<string, mixed>  $config
     * @return \SineMacula\Exporter\Contracts\Exporter
     */
    public function createXmlDriver(array $config): Exporter
    {
        return new Xml($config);
    }

    /**
     * Set the given exporter instance.
     *
     * @param  string  $name
     * @param  \SineMacula\Exporter\Contracts\Exporter  $exporter
     * @return self
     */
    public function set(string $name, Exporter $exporter): self
    {
        $this->exporters[$name] = $exporter;

        return $this;
    }

    /**
     * Get the default driver name.
     *
     * @return string
     */
    public function getDefaultDriver(): string
    {
        return $this->app['config']['exporter.default'];
    }

    /**
     * Unset the given exporter instances.
     *
     * @param  array<int, string>|string  $exporter
     * @return $this
     */
    public function forgetExporter(array|string $exporter): self
    {
        foreach ((array) $exporter as $name) {
            unset($this->exporters[$name]);
        }

        return $this;
    }

    /**
     * Disconnect the given exporter and remove from local cache.
     *
     * @param  string|null  $name
     * @return void
     */
    public function purge(?string $name = null): void
    {
        $name ??= $this->getDefaultDriver();

        unset($this->exporters[$name]);
    }

    /**
     * Register a custom driver creator Closure.
     *
     * @param  string  $driver
     * @param  \Closure(\Illuminate\Contracts\Foundation\Application, array<string, mixed>): \SineMacula\Exporter\Contracts\Exporter  $callback
     * @return self
     */
    #[\Override]
    public function extend(string $driver, \Closure $callback): self
    {
        $this->customCreators[$driver] = $callback;

        return $this;
    }

    /**
     * Set the application instance used by the manager.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return self
     */
    public function setApplication(Application $app): self
    {
        $this->app = $app;

        return $this;
    }

    /**
     * Attempt to get the exporter from the local cache.
     *
     * @param  string  $name
     * @return \SineMacula\Exporter\Contracts\Exporter
     */
    private function get(string $name): Exporter
    {
        return $this->exporters[$name] ?? $this->resolve($name);
    }

    /**
     * Resolve the given exporter.
     *
     * @param  string  $name
     * @param  array<string, mixed>|null  $config
     * @return \SineMacula\Exporter\Contracts\Exporter
     *
     * @throws \InvalidArgumentException
     */
    private function resolve(string $name, ?array $config = null): Exporter
    {
        $config ??= $this->getConfig($name);

        $driver = $config['driver'] ?? null;

        if (!is_string($driver) || $driver === '') {
            throw new \InvalidArgumentException("Exporter [{$name}] does not have a configured driver.");
        }

        if (isset($this->customCreators[$driver])) {
            return $this->callCustomCreator($config);
        }

        return match ($driver) {
            'csv'   => $this->createCsvDriver($config),
            'xml'   => $this->createXmlDriver($config),
            default => throw new \InvalidArgumentException("Driver [{$driver}] is not supported."),
        };
    }

    /**
     * Call a custom driver creator.
     *
     * @param  array<string, mixed>  $config
     * @return \SineMacula\Exporter\Contracts\Exporter
     *
     * @throws \InvalidArgumentException
     */
    private function callCustomCreator(array $config): Exporter
    {
        $driver = $config['driver'] ?? null;

        if (!is_string($driver)) {
            throw new \InvalidArgumentException('Custom driver key must be a string.');
        }

        if (!isset($this->customCreators[$driver])) {
            throw new \InvalidArgumentException("Driver [{$driver}] is not supported.");
        }

        $creator = $this->customCreators[$driver];

        return $creator($this->app, $config);
    }

    /**
     * Get the exporter configuration.
     *
     * @param  string  $name
     * @return array<string, mixed>
     */
    private function getConfig(string $name): array
    {
        $config = $this->app['config']["exporter.exporters.{$name}"];

        return is_array($config)
            ? $config
            : [];
    }
}
