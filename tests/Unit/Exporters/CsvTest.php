<?php

declare(strict_types = 1);

namespace Tests\Unit\Exporters;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Exporters\Csv;
use Tests\Support\Exporters\CsvTestHarness;
use Tests\Support\ResourceTestCase;

/**
 * Tests for CSV export behavior.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Csv::class)]
final class CsvTest extends ResourceTestCase
{
    /**
     * It exports CSV rows with generated headers and escaped values.
     *
     * @return void
     */
    public function testExportArrayGeneratesHeadersAndRows(): void
    {
        $stringable = new class implements \Stringable {
            /**
             * Cast to a display value.
             *
             * @return string
             */
            public function __toString(): string
            {
                return 'memo';
            }
        };

        $exporter = new Csv(['delimiter' => ',', 'enclosure' => '"']);
        $exporter->withoutFields('ignored');

        $csv = $exporter->exportArray([
            [
                'first-name' => 'Alice',
                'quote'      => 'say "hi"',
                'ignored'    => 'hidden',
                'note'       => $stringable,
                'meta'       => ['skip'],
            ],
            [
                'first-name' => 'Bob',
                'quote'      => null,
                'ignored'    => 'hidden',
                'note'       => 'done',
            ],
        ]);

        self::assertSame(
            "\"First Name\",\"Quote\",\"Note\"\n"
            . "\"Alice\",\"say \"\"hi\"\"\",\"memo\"\n"
            . "\"Bob\",\"\",\"done\"\n",
            $csv,
        );
    }

    /**
     * It omits headers when disabled.
     *
     * @return void
     */
    public function testWithoutHeadersOmitsTheHeaderLine(): void
    {
        $exporter = new Csv(['delimiter' => ',', 'enclosure' => '"']);

        $csv = $exporter->withoutHeaders()->exportArray([
            ['name' => 'Alice'],
        ]);

        self::assertSame("\"Alice\"\n", $csv);
    }

    /**
     * It returns empty output when no row contains exportable fields.
     *
     * @return void
     */
    public function testExportArrayReturnsEmptyStringForEmptyPayload(): void
    {
        $exporter = new Csv([]);

        $csv = $exporter->exportArray([
            ['meta' => ['not exportable']],
        ]);

        self::assertSame('', $csv);
    }

    /**
     * It falls back to default delimiter and enclosure for invalid config.
     *
     * @return void
     */
    public function testInvalidDelimiterAndEnclosureConfigFallBackToDefaults(): void
    {
        $exporter = new CsvTestHarness([
            'delimiter' => 123,
            'enclosure' => true,
        ]);

        self::assertSame('"a","b"', $exporter->exposeGenerateRow(['a', 'b']));
        self::assertSame('"A"', $exporter->exposeGenerateColumns(['a']));
    }

    /**
     * It supports custom delimiters and enclosures.
     *
     * @return void
     */
    public function testCustomDelimiterAndEnclosureAreUsed(): void
    {
        $exporter = new CsvTestHarness([
            'delimiter' => ';',
            'enclosure' => '\'',
        ]);

        self::assertSame('\'a\';\'b\'', $exporter->exposeGenerateRow(['a', 'b']));
    }

    /**
     * It exports resource items and collections.
     *
     * @return void
     */
    public function testExportItemAndExportCollection(): void
    {
        $resource = new class (['name' => 'Alice']) extends JsonResource {
            /**
             * Convert the resource to an array.
             *
             * @param  \Illuminate\Http\Request  $request
             * @return array<string, mixed>
             */
            #[\Override]
            public function toArray(mixed $request): array
            {
                $name = is_array($this->resource)
                    ? ($this->resource['name'] ?? '')
                    : '';

                return [
                    'name' => (is_scalar($name) || $name instanceof \Stringable)
                        ? (string) $name
                        : '',
                ];
            }
        };

        $collection = new class ([['name' => 'Alice'], ['name' => 'Bob']]) extends ResourceCollection {
            /**
             * Convert the collection to an array.
             *
             * @param  \Illuminate\Http\Request  $request
             * @return array<int, array<string, mixed>>
             */
            #[\Override]
            public function toArray(mixed $request): array
            {
                $collection = $this->collection ?? collect();

                return $collection->map(
                    static fn (array $item): array => $item,
                )->all();
            }
        };

        $exporter = new Csv([]);

        self::assertSame(
            "\"Name\"\n\"Alice\"\n",
            $exporter->exportItem($resource),
        );

        self::assertSame(
            "\"Name\"\n\"Alice\"\n\"Bob\"\n",
            $exporter->exportCollection($collection),
        );
    }

    /**
     * It exposes header conversion behavior and scalar filtering.
     *
     * @return void
     */
    public function testProtectedHelpersConvertKeysAndFilterValues(): void
    {
        $exporter = new CsvTestHarness([]);
        $exporter->withoutFields(['secret', '0']);

        $columns  = $exporter->exposeGenerateColumns(['first-name', 'last_name']);
        $row      = $exporter->exposeGenerateRow(['A', 2, null]);
        $filtered = $exporter->exposeFilterData([
            0         => 'zero',
            'name'    => 'Alice',
            'secret'  => 'hidden',
            'payload' => new \stdClass,
        ]);

        self::assertSame('"First Name","Last Name"', $columns);
        self::assertSame('"A","2",""', $row);
        self::assertSame(['name' => 'Alice'], $filtered);
        self::assertSame('First Name', $exporter->exposeConvertToWords('first-name'));
        self::assertSame('"say ""hi"""', $exporter->exposeEscapeValue('say "hi"'));
    }

    /**
     * It returns no generated columns when headers are disabled.
     *
     * @return void
     */
    public function testGenerateColumnsReturnsEmptyStringWhenHeadersAreDisabled(): void
    {
        $exporter = new CsvTestHarness([]);
        $exporter->withoutHeaders();

        self::assertSame('', $exporter->exposeGenerateColumns(['name']));
    }
}
