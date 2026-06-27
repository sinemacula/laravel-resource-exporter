<?php

declare(strict_types = 1);

namespace SineMacula\Exporter;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use SineMacula\Exporter\Contracts\ExportFactory;
use SineMacula\Exporter\Http\Middleware\NegotiateExports;

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
        $this->registerMiddleware();
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
     * Register the legacy negotiation middleware, off by default.
     *
     * The middleware is only aliased on the router - never pushed onto a global
     * group - so it stays the documented opt-in legacy on-ramp: applications
     * that want the zero-touch fallback attach the 'exporter.negotiate' alias
     * to a route or group themselves, while the RespondsWithExports trait
     * remains the recommended path. The router is resolved defensively so the
     * bare provider-registration tests, which run without a router, are spared.
     *
     * @return void
     */
    private function registerMiddleware(): void
    {
        $router = $this->app->make('router');

        if (!$router instanceof Router) {
            return;
        }

        $router->aliasMiddleware('exporter.negotiate', NegotiateExports::class);
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
