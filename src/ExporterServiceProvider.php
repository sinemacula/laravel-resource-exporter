<?php

declare(strict_types = 1);

namespace SineMacula\Exporter;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use SineMacula\Exporter\Contracts\ExportFactory;
use SineMacula\Exporter\Http\ExportFormat;
use SineMacula\Exporter\Http\ExportNegotiator;
use SineMacula\Exporter\Http\MediaTypeRegistry;
use SineMacula\Exporter\Http\Middleware\NegotiateExports;
use SineMacula\Exporter\Schema\CastRegistry;
use SineMacula\Exporter\Schema\Contracts\CastRegistry as CastRegistryContract;

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
        $this->registerEngine();
        $this->registerNegotiation();
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
        ], ['config', 'exporter-config']);
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

    /**
     * Bind the cast registry and engine as shared singletons.
     *
     * The cast registry is a single boot-time singleton, so a caster registered
     * on it (app(CastRegistry::class)->register(...)) is reachable to every
     * export. The engine is bound over that registry so the explicit and
     * negotiated paths resolve the same casters; both hold no per-request state
     * and are Octane-safe.
     *
     * @return void
     */
    private function registerEngine(): void
    {
        $this->app->singleton(CastRegistryContract::class, static fn (): CastRegistry => new CastRegistry);

        $this->app->singleton(
            Engine::class,
            static fn (Application $app): Engine => new Engine($app->make(CastRegistryContract::class)),
        );
    }

    /**
     * Bind the content-negotiation collaborators to the service container.
     *
     * The media type registry is a single boot-time singleton seeded from the
     * built-in formats and the config('exporter.formats') extension block, then
     * shared - never mutated per request - so the advertised custom-format seam
     * is reachable and a fresh registry is not allocated on every negotiated
     * response. The negotiator is bound as a singleton over that shared
     * registry so the negotiated and explicit export paths resolve the same
     * formats.
     *
     * @return void
     */
    private function registerNegotiation(): void
    {
        $this->app->singleton(MediaTypeRegistry::class, function (Application $app): MediaTypeRegistry {
            $registry = new MediaTypeRegistry;

            foreach ($this->configuredFormats($app) as $format) {
                $registry->register($format);
            }

            $default = $app['config']->get('exporter.negotiation.default_format');

            if (is_string($default) && $default !== '') {
                $registry->setDefault($default);
            }

            return $registry;
        });

        $this->app->singleton(
            ExportNegotiator::class,
            static fn (Application $app): ExportNegotiator => new ExportNegotiator(
                $app->make(MediaTypeRegistry::class),
                $app->make(Engine::class),
            ),
        );
    }

    /**
     * Resolve the custom formats declared in the configuration block.
     *
     * Each entry may be an ExportFormat instance, a Closure returning one, or
     * the class name of a container binding that resolves to one; anything else
     * is ignored so a malformed entry never aborts boot. A bound container key
     * is resolved through the container so a format that carries a
     * writer-factory closure can be registered from a binding and stay
     * compatible with config caching.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return list<\SineMacula\Exporter\Http\ExportFormat>
     */
    private function configuredFormats(Application $app): array
    {
        $formats = $app['config']->get('exporter.formats', []);

        if (!is_array($formats)) {
            return [];
        }

        $resolved = [];

        foreach ($formats as $format) {

            if ($format instanceof \Closure) {
                $format = $format($app);
            } elseif (is_string($format) && $app->bound($format)) {
                $format = $app->make($format);
            }

            if (!$format instanceof ExportFormat) {
                continue;
            }

            $resolved[] = $format;
        }

        return $resolved;
    }
}
