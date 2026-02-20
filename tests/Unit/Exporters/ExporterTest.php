<?php

declare(strict_types = 1);

namespace Tests\Unit\Exporters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\Exporter\Exporters\Exporter as BaseExporter;
use Stringable;
use Tests\Support\Exporters\ExporterTestHarness;
use Tests\Support\ResourceTestCase;

/**
 * Tests for the base exporter behavior.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(BaseExporter::class)]
final class ExporterTest extends ResourceTestCase
{
    /**
     * It merges default and runtime exporter configuration.
     *
     * @return void
     */
    public function testConstructorMergesDefaultAndRuntimeConfig(): void
    {
        $exporter = new ExporterTestHarness([
            'delimiter' => ',',
            'runtime'   => 'value',
        ]);

        self::assertSame(
            [
                'delimiter' => ',',
                'enclosure' => '"',
                'runtime'   => 'value',
            ],
            $exporter->getConfig(),
        );
    }

    /**
     * It stores ignored fields passed as either string or array.
     *
     * @return void
     */
    public function testWithoutFieldsAcceptsStringAndArrayValues(): void
    {
        $exporter = new ExporterTestHarness([]);

        self::assertSame($exporter, $exporter->withoutFields('secret'));
        self::assertSame(['secret'], $exporter->ignoredFields());

        $exporter->withoutFields(['alpha', 'beta']);

        self::assertSame(['alpha', 'beta'], $exporter->ignoredFields());
    }

    /**
     * It identifies values that can be string-cast safely.
     *
     * @param  mixed  $value
     * @param  bool  $expected
     * @return void
     */
    #[DataProvider('stringableProvider')]
    public function testIsStringableHandlesSupportedAndUnsupportedValues(
        mixed $value,
        bool $expected,
    ): void {
        $exporter = new ExporterTestHarness([]);

        self::assertSame($expected, $exporter->exposeIsStringable($value));
    }

    /**
     * Provide stringable and non-stringable values.
     *
     * @return iterable<string, array{0: mixed, 1: bool}>
     */
    public static function stringableProvider(): iterable
    {
        yield 'integer' => [1, true];
        yield 'float' => [1.5, true];
        yield 'boolean' => [true, true];
        yield 'string' => ['value', true];
        yield 'null' => [null, true];
        yield 'stringable object' => [
            new class implements \Stringable {
                /**
                 * Cast the value to string.
                 *
                 * @return string
                 */
                public function __toString(): string
                {
                    return 'value';
                }
            },
            true,
        ];
        yield 'array' => [['value'], false];
        yield 'object' => [new \stdClass, false];
    }
}
