<?php

declare(strict_types = 1);

namespace SineMacula\Exporter;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use SineMacula\Exporter\Contracts\ExportFactory;

/**
 * Exporter service provider.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ExporterServiceProvider extends ServiceProvider
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
     * Publish any package specific configuration and assets.
     *
     * @return void
     */
    private function offerPublishing(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        // config_path() is always defined inside a booted Laravel app, so this
        // defensive guard cannot be exercised without subclassing the provider.
        // @codeCoverageIgnoreStart
        if (!function_exists('config_path')) {
            return;
        }
        // @codeCoverageIgnoreEnd
        $this->publishes([
            __DIR__ . '/../config/exporter.php' => config_path('exporter.php'),
        ], 'config');
    }

    /**
     * Bind the exporter to the service container.
     *
     * @return void
     */
    private function registerManager(): void
    {
        $this->app->singleton(ExportManager::class, fn ($app) => new ExportManager($app));

        $this->app->alias(ExportManager::class, Config::get('exporter.alias'));
        $this->app->alias(ExportManager::class, ExportFactory::class);
    }
}
