<?php

namespace SineMacula\Exporter;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use SineMacula\Exporter\Contracts\Exporter;
use SineMacula\Exporter\Exporters\Csv;
use SineMacula\Exporter\Exporters\Xml;

/**
 * The export manager.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 *
 * @mixin \SineMacula\Exporter\Contracts\Exporter
 */
final class ExportManager
{
    /** @var array The array of resolved exporters */
    protected array $exporters = [];

    /** @var array The registered custom driver creators */
    protected array $customCreators = [];

    /**
     * Create a new export manager instance.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     */
    public function __construct(

        /** The application instance */
        public Application $app

    ) {}

    /**
     * Get an export instance.
     *
     * @param  string|null  $name
     * @return \SineMacula\Exporter\Contracts\Exporter
     */
    public function format(string $name = null): Exporter
    {
        $name = $name ?: $this->getDefaultDriver();

        return $this->exporters[$name] = $this->get($name);
    }

    /**
     * Build an on-demand exporter.
     *
     * @param  array|null  $config
     * @return \SineMacula\Exporter\Contracts\Exporter
     */
    public function build(array $config = null): Exporter
    {
        return $this->resolve('ondemand', $config ?? ['driver' => $this->getDefaultDriver()]);
    }

    /**
     * Attempt to get the exporter from the local cache.
     *
     * @param  string  $name
     * @return \SineMacula\Exporter\Contracts\Exporter
     */
    protected function get(string $name): Exporter
    {
        return $this->exporters[$name] ?? $this->resolve($name);
    }

    /**
     * Resolve the given exporter.
     *
     * @param  string  $name
     * @param  array|null  $config
     * @return \SineMacula\Exporter\Contracts\Exporter
     *
     * @throws \InvalidArgumentException
     */
    protected function resolve(string $name, array $config = null): Exporter
    {
        $config ??= $this->getConfig($name);

        if (empty($config['driver'])) {
            throw new InvalidArgumentException("Exporter [{$name}] does not have a configured driver.");
        }

        $name = $config['driver'];

        if (isset($this->customCreators[$name])) {
            return $this->callCustomCreator($config);
        }

        $driver_method = 'create' . ucfirst($name) . 'Driver';

        if (!method_exists($this, $driver_method)) {
            throw new InvalidArgumentException("Driver [{$name}] is not supported.");
        }

        return $this->{$driver_method}($config);
    }

    /**
     * Call a custom driver creator.
     *
     * @param  array  $config
     * @return \SineMacula\Exporter\Contracts\Exporter
     */
    protected function callCustomCreator(array $config): Exporter
    {
        return $this->customCreators[$config['driver']]($this->app, $config);
    }

    /**
     * Create an instance of the CSV driver.
     *
     * @param  array  $config
     * @return \SineMacula\Exporter\Contracts\Exporter
     */
    public function createCsvDriver(array $config): Exporter
    {
        return new Csv($config);
    }

    /**
     * Create an instance of the XML driver.
     *
     * @param  array  $config
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
     * Get the exporter configuration.
     *
     * @param  string  $name
     * @return array
     */
    protected function getConfig(string $name): array
    {
        return $this->app['config']["exporter.exporters.{$name}"] ?: [];
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
     * @param  array|string  $exporter
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
     * @param  \Closure  $callback
     * @return self
     */
    public function extend(string $driver, Closure $callback): self
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
     * Dynamically call the default driver instance.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->format()->{$method}(...$parameters);
    }
}
