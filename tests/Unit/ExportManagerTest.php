<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Illuminate\Contracts\Config\Repository;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Contracts\Exporter as ExporterContract;
use SineMacula\Exporter\Export\QueuedExport;
use SineMacula\Exporter\ExportBuilder;
use SineMacula\Exporter\Exporters\Csv;
use SineMacula\Exporter\Exporters\Xml;
use SineMacula\Exporter\ExportManager;
use Tests\Support\ExportManagerFakeExporter;
use Tests\Support\V3\Models\User;
use Tests\Support\V3\Resources\UserResource;

/**
 * Tests for manager driver resolution and delegation behavior.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExportManager::class)]
final class ExportManagerTest extends TestCase
{
    /**
     * It resolves named and default drivers.
     *
     * @return void
     */
    public function testFormatResolvesDefaultAndNamedDrivers(): void
    {
        $manager = $this->makeManager();

        self::assertInstanceOf(Csv::class, $manager->format());
        self::assertInstanceOf(Xml::class, $manager->format('xml'));
    }

    /**
     * It creates on-demand exporters with and without explicit config.
     *
     * @return void
     */
    public function testBuildCreatesExportersFromDefaultOrExplicitDriver(): void
    {
        $manager = $this->makeManager();

        self::assertInstanceOf(Csv::class, $manager->build());
        self::assertInstanceOf(Xml::class, $manager->build(['driver' => 'xml']));
    }

    /**
     * It delegates valid calls and rejects unsupported ones.
     *
     * @return void
     */
    public function testCallProxiesToDefaultDriverAndThrowsForUnknownMethod(): void
    {
        $manager = $this->makeManager();
        $config  = $manager->getConfig();

        self::assertIsArray($config);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Method [missingMethod] is not supported.');

        $manager->__call('missingMethod', []);
    }

    /**
     * It supports direct driver factory methods.
     *
     * @return void
     */
    public function testCreateDriverMethodsReturnExpectedInstances(): void
    {
        $manager = $this->makeManager();

        self::assertInstanceOf(Csv::class, $manager->createCsvDriver([]));
        self::assertInstanceOf(Xml::class, $manager->createXmlDriver([]));
    }

    /**
     * It supports caching and explicit cache replacement.
     *
     * @return void
     */
    public function testSetAndForgetExporterCacheEntries(): void
    {
        $manager      = $this->makeManager();
        $fakeExporter = new ExportManagerFakeExporter(['driver' => 'fake']);

        $manager->set('csv', $fakeExporter);

        self::assertSame($fakeExporter, $manager->format('csv'));

        $manager->forgetExporter('csv');

        self::assertInstanceOf(Csv::class, $manager->format('csv'));
    }

    /**
     * It forgets multiple exporters and purges default and named caches.
     *
     * @return void
     */
    public function testForgetAndPurgeRemoveCachedInstances(): void
    {
        $manager = $this->makeManager();

        $manager->format('csv');
        $manager->format('xml');
        $manager->forgetExporter(['csv', 'xml']);

        $firstCsv = $manager->format('csv');
        $manager->purge('csv');
        $secondCsv = $manager->format('csv');

        self::assertNotSame($firstCsv, $secondCsv);

        $firstDefault = $manager->format();
        $manager->purge();
        $secondDefault = $manager->format();

        self::assertNotSame($firstDefault, $secondDefault);
    }

    /**
     * It purges only the named exporter, never the default driver entry.
     *
     * @return void
     */
    public function testPurgeRemovesOnlyTheExplicitlyNamedExporter(): void
    {
        $manager = $this->makeManager();

        self::assertSame('csv', $manager->getDefaultDriver());

        $cachedCsv = new ExportManagerFakeExporter(['driver' => 'csv']);
        $cachedXml = new ExportManagerFakeExporter(['driver' => 'xml']);

        $manager->set('csv', $cachedCsv);
        $manager->set('xml', $cachedXml);

        $manager->purge('xml');

        // Only the named 'xml' cache entry is removed; the default 'csv' entry
        // must survive (purging the default name instead would drop 'csv').
        self::assertSame($cachedCsv, $manager->format('csv'));
        self::assertNotSame($cachedXml, $manager->format('xml'));
    }

    /**
     * It resolves custom creators registered through extend.
     *
     * @return void
     */
    public function testExtendRegistersAndResolvesCustomCreators(): void
    {
        $manager = $this->makeManager([
            'exporter.exporters.custom' => ['driver' => 'custom'],
        ]);

        $manager->extend(
            'custom',
            static fn ($app, array $config): ExporterContract => new ExportManagerFakeExporter($config),
        );

        $exporter = $manager->format('custom');

        self::assertInstanceOf(ExportManagerFakeExporter::class, $exporter);
        self::assertSame(['driver' => 'custom'], $exporter->getConfig());
    }

    /**
     * It reports missing or unsupported drivers.
     *
     * @return void
     */
    public function testResolveThrowsForMissingOrUnsupportedDrivers(): void
    {
        $manager = $this->makeManager([
            'exporter.exporters.missing' => [],
            'exporter.exporters.invalid' => ['driver' => 'unsupported'],
        ]);

        try {
            $manager->format('missing');
            self::fail('Expected missing driver exception.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(
                'Exporter [missing] does not have a configured driver.',
                $exception->getMessage(),
            );
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Driver [unsupported] is not supported.');

        $manager->format('invalid');
    }

    /**
     * It supports replacing the manager application instance.
     *
     * @return void
     */
    public function testSetApplicationUpdatesTheManagerContext(): void
    {
        $manager = $this->makeManager();

        self::assertSame($manager, $manager->setApplication($this->app));
        self::assertSame('csv', $manager->getDefaultDriver());
    }

    /**
     * It exposes private creator validation branches via reflection.
     *
     * @return void
     */
    public function testPrivateCallCustomCreatorValidationPaths(): void
    {
        $manager           = $this->makeManager();
        $callCustomCreator = \Closure::bind(
            static fn (ExportManager $instance, array $config): mixed => $instance->callCustomCreator($config),
            null,
            ExportManager::class,
        );

        try {
            $callCustomCreator($manager, ['driver' => ['bad']]);
            self::fail('Expected string validation exception.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Custom driver key must be a string.', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Driver [missing-custom] is not supported.');

        $callCustomCreator($manager, ['driver' => 'missing-custom']);
    }

    /**
     * It returns empty config when exporter config is not an array.
     *
     * @return void
     */
    public function testGetConfigReturnsEmptyArrayForNonArrayConfigValues(): void
    {
        $manager = $this->makeManager([
            'exporter.exporters.bad' => 'invalid',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Exporter [bad] does not have a configured driver.');

        $manager->format('bad');
    }

    /**
     * It opens fluent v3 export builders and the queued export entry point.
     *
     * @return void
     */
    public function testFluentEntryPointsBuildExports(): void
    {
        $manager    = $this->makeManager();
        $collection = UserResource::collection(collect([])); // @phpstan-ignore staticMethod.dynamicCall

        self::assertInstanceOf(ExportBuilder::class, $manager->export(User::query()));
        self::assertInstanceOf(ExportBuilder::class, $manager->collection($collection));
        self::assertInstanceOf(ExportBuilder::class, $manager->query(User::query(), UserResource::class));
        self::assertInstanceOf(QueuedExport::class, $manager->queue(User::class, UserResource::class));
    }

    /**
     * Build a manager with deterministic config values.
     *
     * @param  array<string, mixed>  $overrides
     * @return \SineMacula\Exporter\ExportManager
     */
    private function makeManager(array $overrides = []): ExportManager
    {
        self::assertNotNull($this->app);

        $config = [
            'exporter.default'       => 'csv',
            'exporter.exporters.csv' => ['driver' => 'csv'],
            'exporter.exporters.xml' => ['driver' => 'xml'],
            ...$overrides,
        ];

        $repository = $this->configRepository();

        foreach ($config as $key => $value) {
            $repository->set($key, $value);
        }

        return new ExportManager($this->app);
    }

    /**
     * Resolve the configuration repository from the test application.
     *
     * @return \Illuminate\Contracts\Config\Repository
     */
    private function configRepository(): Repository
    {
        self::assertNotNull($this->app);

        $repository = $this->app->make('config');
        self::assertInstanceOf(Repository::class, $repository);

        return $repository;
    }
}
