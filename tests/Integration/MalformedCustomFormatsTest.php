<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\ExporterServiceProvider;
use SineMacula\Exporter\Http\MediaTypeRegistry;
use Tests\Support\ExporterTestCase;

/**
 * Integration coverage for malformed custom-format configuration.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExporterServiceProvider::class)]
final class MalformedCustomFormatsTest extends ExporterTestCase
{
    /**
     * A non-array exporter.formats value is ignored without disabling the
     * built-in registry entries.
     *
     * @return void
     */
    public function testNonArrayCustomFormatsConfigIsIgnored(): void
    {
        $app = $this->app;

        self::assertInstanceOf(Application::class, $app);

        $registry = $app->make(MediaTypeRegistry::class);

        self::assertTrue($registry->has('csv'));
        self::assertFalse($registry->has('report'));
    }

    /**
     * Configure a malformed custom-format block before the provider registers.
     *
     * @param  mixed  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        if (!$app instanceof Application) {
            return;
        }

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app->make('config');

        $config->set('exporter.formats', 'not-an-array');
    }
}
