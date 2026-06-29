<?php

declare(strict_types = 1);

namespace Tests\Integration\Container;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Contracts\ExportFactory;
use SineMacula\Exporter\ExporterServiceProvider;
use SineMacula\Exporter\ExportManager;
use SineMacula\Exporter\Facades\Exporter as ExporterFacade;
use Tests\Support\ExporterTestCase;
use Tests\Support\ExportManagerFakeExporter;

/**
 * Integration tests for the container-binding fix (the must-fix from §11).
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExporterServiceProvider::class)]
#[CoversClass(ExportManager::class)]
final class ExportFactoryBindingTest extends ExporterTestCase
{
    /**
     * It binds one singleton under the class, alias and factory contract.
     *
     * @return void
     */
    public function testManagerClassAliasAndContractResolveTheSameSingleton(): void
    {
        $app = $this->app;

        self::assertInstanceOf(Application::class, $app);

        $byClass    = $app->make(ExportManager::class);
        $byAlias    = $app->make('exporter');
        $byContract = $app->make(ExportFactory::class);

        self::assertInstanceOf(ExportManager::class, $byClass);
        self::assertSame($byClass, $byAlias);
        self::assertSame($byClass, $byContract);
    }

    /**
     * It makes a driver registered through the resolved manager visible to the
     * facade (the bug the binding fix closes).
     *
     * @return void
     */
    public function testDriverRegisteredThroughTheManagerIsVisibleThroughTheFacade(): void
    {
        $app = $this->app;

        self::assertInstanceOf(Application::class, $app);

        $config = $app->make('config');

        self::assertInstanceOf(Repository::class, $config);
        $config->set('exporter.exporters.custom', ['driver' => 'custom']);

        $app->make(ExportManager::class)->extend(
            'custom',
            static fn (Application $app, array $config): ExportManagerFakeExporter => new ExportManagerFakeExporter($config),
        );

        $exporter = ExporterFacade::format('custom');

        self::assertInstanceOf(ExportManagerFakeExporter::class, $exporter);
        self::assertSame(['driver' => 'custom'], $exporter->getConfig());
    }
}
