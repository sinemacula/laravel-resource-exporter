<?php

declare(strict_types = 1);

namespace Tests\Integration\Container;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\ExporterServiceProvider;
use SineMacula\Exporter\ExportManager;
use SineMacula\Exporter\Http\ExportNegotiator;
use SineMacula\Exporter\Http\MediaTypeRegistry;
use SineMacula\Exporter\Http\Middleware\NegotiateExports;

/**
 * Integration tests for package container wiring and runtime behavior.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExporterServiceProvider::class)]
#[CoversClass(ExportManager::class)]
final class ExporterPackageIntegrationTest extends TestCase
{
    /**
     * It registers the configured container alias and manager instance.
     *
     * @return void
     */
    public function testRegistersConfiguredAliasAndManagerBinding(): void
    {
        $app = $this->application();

        self::assertTrue($app->bound('exporter'));

        $manager = $app->make('exporter');

        self::assertInstanceOf(ExportManager::class, $manager);
    }

    /**
     * It registers the content negotiator as a shared singleton.
     *
     * @return void
     */
    public function testRegistersTheNegotiatorAsASingleton(): void
    {
        $app = $this->application();

        self::assertTrue($app->bound(MediaTypeRegistry::class));
        self::assertTrue($app->bound(ExportNegotiator::class));

        $negotiator = $app->make(ExportNegotiator::class);

        self::assertInstanceOf(ExportNegotiator::class, $negotiator);
        self::assertSame($negotiator, $app->make(ExportNegotiator::class));
    }

    /**
     * It registers the negotiation middleware alias without applying it
     * globally.
     *
     * @return void
     */
    public function testRegistersTheNegotiationMiddlewareAlias(): void
    {
        $router = $this->application()->make('router');

        self::assertInstanceOf(Router::class, $router);
        self::assertSame(NegotiateExports::class, $router->getMiddleware()['exporter.negotiate'] ?? null);
    }

    /**
     * Register package providers for the integration test app.
     *
     * @param  mixed  $app
     * @return array<int, class-string>
     */
    #[\Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [
            ExporterServiceProvider::class,
        ];
    }

    /**
     * Configure package defaults for integration scenarios.
     *
     * @param  mixed  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        if (!$app instanceof Application) {
            return;
        }

        $config = $this->config($app);

        $config->set('exporter.alias', 'exporter');
        $config->set('exporter.default', 'csv');
    }

    /**
     * Return the active integration application instance.
     *
     * @return \Illuminate\Foundation\Application
     */
    private function application(): Application
    {
        assert($this->app instanceof Application);

        return $this->app;
    }

    /**
     * Resolve the configuration repository from the application container.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return \Illuminate\Contracts\Config\Repository
     */
    private function config(Application $app): Repository
    {
        $config = $app->make('config');
        assert($config instanceof Repository);

        return $config;
    }
}
