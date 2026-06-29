<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Contracts\Exporter as ExporterContract;
use SineMacula\Exporter\ExporterServiceProvider;
use SineMacula\Exporter\ExportManager;
use SineMacula\Exporter\Facades\Exporter as ExporterFacade;
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
        self::assertSame('csv', $manager->getDefaultDriver());
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
     * It registers the legacy negotiation middleware alias without applying it
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
     * It resolves facade calls through the configured alias and default driver.
     *
     * @return void
     */
    public function testFacadeExportsCsvThroughDefaultDriver(): void
    {
        $csv = ExporterFacade::exportArray([
            [
                'first-name' => 'Alice',
                'age'        => 30,
            ],
        ]);

        self::assertSame("\"First Name\",\"Age\"\n\"Alice\",\"30\"\n", $csv);
    }

    /**
     * It resolves and executes xml driver exports through the manager.
     *
     * @return void
     */
    public function testManagerResolvesXmlDriverInLaravelContainer(): void
    {
        $app     = $this->application();
        $manager = $this->manager($app);

        $xmlString = $manager->format('xml')->exportArray([
            [
                'name' => 'Alice',
            ],
        ]);

        $xml = simplexml_load_string($xmlString);

        self::assertInstanceOf(\SimpleXMLElement::class, $xml);
        self::assertSame('Items', $xml->getName());
        self::assertSame('Alice', (string) $xml->Item->Name);
    }

    /**
     * It supports custom driver extensions in the integrated application.
     *
     * @return void
     */
    public function testManagerResolvesCustomExtendedDriver(): void
    {
        $app     = $this->application();
        $manager = $this->manager($app);
        $config  = $this->config($app);

        $config->set('exporter.exporters.custom', ['driver' => 'custom']);

        $manager->extend(
            'custom',
            static fn (Application $app, array $config): ExporterContract => new class ($config) implements ExporterContract {
                /**
                 * Create the fake exporter.
                 *
                 * @param  array<string, mixed>  $config
                 */
                public function __construct(

                    /** @var array<string, mixed> */
                    private array $config,
                ) {}

                /**
                 * Return the exporter configuration.
                 *
                 * @return array<string, mixed>
                 */
                #[\Override]
                public function getConfig(): array
                {
                    return $this->config;
                }

                /**
                 * Ignore fields for chained calls.
                 *
                 * @param  array<int, string>|string  $fields
                 * @return static
                 */
                #[\Override]
                public function withoutFields(array|string $fields): static
                {
                    return $this;
                }

                /**
                 * Export array payloads.
                 *
                 * @param  array<int, array<string, mixed>>  $rows
                 * @return string
                 */
                #[\Override]
                public function exportArray(array $rows): string
                {
                    return '';
                }

                /**
                 * Export JsonResource payloads.
                 *
                 * @param  \Illuminate\Http\Resources\Json\JsonResource  $resource
                 * @return string
                 */
                #[\Override]
                public function exportItem(JsonResource $resource): string
                {
                    return '';
                }

                /**
                 * Export ResourceCollection payloads.
                 *
                 * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
                 * @return string
                 */
                #[\Override]
                public function exportCollection(ResourceCollection $collection): string
                {
                    return '';
                }
            },
        );

        $exporter = $manager->format('custom');

        self::assertSame(['driver' => 'custom'], $exporter->getConfig());
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
        $config->set('exporter.exporters.csv', ['driver' => 'csv']);
        $config->set('exporter.exporters.xml', ['driver' => 'xml', 'pretty_print' => false]);
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
     * Resolve the package manager from the application container.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return \SineMacula\Exporter\ExportManager
     */
    private function manager(Application $app): ExportManager
    {
        $manager = $app->make('exporter');
        assert($manager instanceof ExportManager);

        return $manager;
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
