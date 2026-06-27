<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Contracts\ExportFactory;
use SineMacula\Exporter\ExporterServiceProvider;
use SineMacula\Exporter\ExportManager;
use Tests\Support\ProviderAppStub;

/**
 * Tests for package service provider registration and publishing behavior.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExporterServiceProvider::class)]
final class ExporterServiceProviderTest extends TestCase
{
    /**
     * Reset static publish state and facade app before each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetPublishState();
        Config::setFacadeApplication(null);
        Config::clearResolvedInstance('config');
    }

    /**
     * Clear facade application after each test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        Config::setFacadeApplication(null);
        Config::clearResolvedInstance('config');

        parent::tearDown();
    }

    /**
     * It merges package config and binds the configured manager alias.
     *
     * @return void
     */
    public function testRegisterMergesConfigAndRegistersManagerAlias(): void
    {
        $app = new ProviderAppStub([
            'exporter.alias' => 'custom-exporter',
        ]);

        Config::setFacadeApplication($app);

        $provider = new ExporterServiceProvider($app);
        $provider->register();

        self::assertArrayHasKey(ExportManager::class, $app->singletonBindings());
        self::assertSame(ExportManager::class, $app->aliasBindings()['custom-exporter']);
        self::assertSame(ExportManager::class, $app->aliasBindings()[ExportFactory::class]);
        self::assertSame('csv', $app->config()->get('exporter.default'));
        self::assertSame('csv', $app->config()->get('exporter.exporters.csv.driver'));
        self::assertSame('xml', $app->config()->get('exporter.exporters.xml.driver'));
    }

    /**
     * It exits publish flow when not running in console.
     *
     * @return void
     */
    public function testBootSkipsPublishingWhenNotInConsole(): void
    {
        $app = new ProviderAppStub(
            [
                'exporter.alias' => 'exporter',
            ],
            false,
        );

        $provider = new ExporterServiceProvider($app);
        $provider->boot();

        self::assertSame(
            [],
            ExporterServiceProvider::pathsToPublish(ExporterServiceProvider::class, 'config'),
        );
    }

    /**
     * It publishes the package config file when console publishing is enabled.
     *
     * @return void
     */
    public function testBootRegistersConfigPublishMapping(): void
    {
        $app = new ProviderAppStub(
            [
                'exporter.alias' => 'exporter',
            ],
            true,
        );

        $provider  = new ExporterServiceProvider($app);
        $container = new class extends Container {
            /**
             * Resolve config path values for helper calls.
             *
             * @param  string  $path
             * @return string
             */
            public function configPath(string $path = ''): string
            {
                return '/virtual/config/' . ltrim($path, '/');
            }
        };

        Container::setInstance($container);

        try {
            $provider->boot();
        } finally {
            Container::setInstance(null);
        }

        $publishable = ExporterServiceProvider::pathsToPublish(ExporterServiceProvider::class, 'config');
        $source      = (string) array_key_first($publishable);

        self::assertCount(1, $publishable);
        self::assertStringEndsWith('/config/exporter.php', $source);
        self::assertSame(
            realpath(dirname(__DIR__, 2) . '/config/exporter.php'),
            realpath($source),
        );
        self::assertSame('/virtual/config/exporter.php', array_values($publishable)[0]);
    }

    /**
     * Reset publish caches on the base service provider.
     *
     * @return void
     */
    private function resetPublishState(): void
    {
        $resetter = \Closure::bind(
            static function (): void {
                ServiceProvider::$publishes     = [];
                ServiceProvider::$publishGroups = [];
            },
            null,
            ServiceProvider::class,
        );

        $resetter();
    }
}
