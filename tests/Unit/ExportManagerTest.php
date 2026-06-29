<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Export\QueuedExport;
use SineMacula\Exporter\ExportBuilder;
use SineMacula\Exporter\ExportManager;
use Tests\Support\Models\User;
use Tests\Support\Resources\UserResource;

/**
 * Tests for the export manager's fluent entry points.
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
     * It opens fluent export builders and the queued export entry point.
     *
     * @return void
     */
    public function testFluentEntryPointsBuildExports(): void
    {
        self::assertNotNull($this->app);

        $manager    = new ExportManager($this->app);
        $collection = UserResource::collection(collect([])); // @phpstan-ignore staticMethod.dynamicCall

        self::assertInstanceOf(ExportBuilder::class, $manager->export(User::query()));
        self::assertInstanceOf(ExportBuilder::class, $manager->collection($collection));
        self::assertInstanceOf(ExportBuilder::class, $manager->query(User::query(), UserResource::class));
        self::assertInstanceOf(QueuedExport::class, $manager->queue(User::class, UserResource::class));
    }
}
