<?php

declare(strict_types = 1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the EXPORTER_DEFAULT environment binding of the default format.
 *
 * These tests evaluate the shipped config file directly so the env binding is
 * pinned by behaviour, not by reading the source.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversNothing]
final class ConfigDefaultEnvTest extends TestCase
{
    /** @var string The path to the shipped package configuration file */
    private const string CONFIG_PATH = __DIR__ . '/../../config/exporter.php';

    /**
     * Restore the environment variable after each test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        unset($_ENV['EXPORTER_DEFAULT'], $_SERVER['EXPORTER_DEFAULT']);
        putenv('EXPORTER_DEFAULT');

        parent::tearDown();
    }

    /**
     * It resolves exporter.default from the EXPORTER_DEFAULT environment value.
     *
     * @return void
     */
    public function testDefaultResolvesFromTheRenamedEnvironmentVariable(): void
    {
        $_ENV['EXPORTER_DEFAULT']    = 'tsv';
        $_SERVER['EXPORTER_DEFAULT'] = 'tsv';
        putenv('EXPORTER_DEFAULT=tsv');

        $config = $this->loadConfig();

        self::assertSame('tsv', $config['default']);
    }

    /**
     * It falls back to csv when EXPORTER_DEFAULT is not set.
     *
     * @return void
     */
    public function testDefaultFallsBackToCsvWhenUnset(): void
    {
        unset($_ENV['EXPORTER_DEFAULT'], $_SERVER['EXPORTER_DEFAULT']);
        putenv('EXPORTER_DEFAULT');

        $config = $this->loadConfig();

        self::assertSame('csv', $config['default']);
    }

    /**
     * It ships the negotiation block with the documented defaults, including
     * the 10,000-row synchronous export cap and a null negotiation fallback.
     *
     * @return void
     */
    public function testNegotiationBlockShipsWithTheDocumentedDefaults(): void
    {
        unset(
            $_ENV['EXPORTER_NEGOTIATION_DEFAULT'],
            $_SERVER['EXPORTER_NEGOTIATION_DEFAULT'],
            $_ENV['EXPORTER_MAX_ROWS'],
            $_SERVER['EXPORTER_MAX_ROWS'],
            $_ENV['EXPORTER_PER_PAGE'],
            $_SERVER['EXPORTER_PER_PAGE'],
            $_ENV['EXPORTER_CHUNK_SIZE'],
            $_SERVER['EXPORTER_CHUNK_SIZE'],
        );
        putenv('EXPORTER_NEGOTIATION_DEFAULT');
        putenv('EXPORTER_MAX_ROWS');
        putenv('EXPORTER_PER_PAGE');
        putenv('EXPORTER_CHUNK_SIZE');

        $config = $this->loadConfig();

        self::assertIsArray($config['negotiation']);
        self::assertNull($config['negotiation']['default_format']);
        self::assertSame(10000, $config['negotiation']['max_rows']);
        self::assertSame(15, $config['negotiation']['per_page']);
        self::assertSame(1000, $config['negotiation']['chunk_size']);
    }

    /**
     * Evaluate the shipped configuration file and return its array.
     *
     * @return array<array-key, mixed>
     */
    private function loadConfig(): array
    {
        $config = require self::CONFIG_PATH;

        self::assertIsArray($config);

        return $config;
    }
}
