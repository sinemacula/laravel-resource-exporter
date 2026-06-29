<?php

declare(strict_types = 1);

namespace Tests\Integration\Container;

use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Contracts\ExportFactory;
use SineMacula\Exporter\ExporterServiceProvider;
use SineMacula\Exporter\ExportManager;
use Tests\Support\ExporterTestCase;

/**
 * Integration tests for the export-manager container binding.
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
}
