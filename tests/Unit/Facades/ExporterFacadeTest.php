<?php

declare(strict_types = 1);

namespace Tests\Unit\Facades;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Facades\Exporter as ExporterFacade;
use Tests\Support\Facades\FacadeAppStub;

/**
 * Tests for facade accessor resolution.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExporterFacade::class)]
final class ExporterFacadeTest extends TestCase
{
    /**
     * Reset facade state before each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::setFacadeApplication(null);
        Config::clearResolvedInstance('config');
    }

    /**
     * Clear facade state after each test.
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
     * It returns configured accessor names when valid.
     *
     * @return void
     */
    public function testGetFacadeAccessorReturnsConfiguredAlias(): void
    {
        Config::setFacadeApplication(
            new FacadeAppStub([
                'exporter.alias' => 'custom-exporter',
            ]),
        );

        self::assertSame('custom-exporter', $this->invokeFacadeAccessor());
    }

    /**
     * It falls back to default accessor when alias is not a string.
     *
     * @return void
     */
    public function testGetFacadeAccessorFallsBackWhenAliasIsNotString(): void
    {
        Config::setFacadeApplication(
            new FacadeAppStub([
                'exporter.alias' => ['invalid'],
            ]),
        );

        self::assertSame('exporter', $this->invokeFacadeAccessor());
    }

    /**
     * Invoke the protected facade accessor on the real facade.
     *
     * @return string
     *
     * @throws \ReflectionException
     */
    private function invokeFacadeAccessor(): string
    {
        $method = new \ReflectionMethod(ExporterFacade::class, 'getFacadeAccessor');

        $accessor = $method->invoke(null);

        self::assertIsString($accessor);

        return $accessor;
    }
}
