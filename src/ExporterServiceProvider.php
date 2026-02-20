<?php

namespace SineMacula\Exporter;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

/**
 * Exporter service provider.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class ExporterServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->offerPublishing();
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/exporter.php',
            'exporter',
        );

        $this->registerManager();
    }

    /**
     * Resolve the configuration publish path.
     *
     * @param  string  $path
     * @return string
     */
    protected function resolveConfigPath(string $path): string
    {
        return config_path($path);
    }

    /**
     * Determine if the config_path helper is available.
     *
     * @return bool
     */
    protected function hasConfigPathFunction(): bool
    {
        return function_exists('config_path');
    }

    /**
     * Publish any package specific configuration and assets.
     *
     * @return void
     */
    private function offerPublishing(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        if (!$this->hasConfigPathFunction()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/exporter.php' => $this->resolveConfigPath('exporter.php'),
        ], 'config');
    }

    /**
     * Bind the exporter to the service container.
     *
     * @return void
     */
    private function registerManager(): void
    {
        $this->app->singleton(Config::get('exporter.alias'), fn ($app) => new ExportManager($app));
    }
}
