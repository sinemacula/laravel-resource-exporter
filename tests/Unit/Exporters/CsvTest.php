<?php

declare(strict_types = 1);

namespace Tests\Unit\Exporters;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Exporters\Csv;
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
            #[\Override]
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
     * It neutralises spreadsheet formula triggers in string fields.
     *
     * @return void
     */
    public function testNeutralisesSpreadsheetFormulaTriggers(): void
    {
        $csv = (new Csv([]))->exportArray([
            [
                'equals' => '=1+2',
                'plus'   => '+1',
                'minus'  => '-1',
                'at'     => '@SUM(A1)',
                'tab'    => "\tcmd",
                'safe'   => 'hello',
            ],
        ]);

        self::assertStringContainsString('"\'=1+2"', $csv);
        self::assertStringContainsString('"\'+1"', $csv);
        self::assertStringContainsString('"\'-1"', $csv);
        self::assertStringContainsString('"\'@SUM(A1)"', $csv);
        self::assertStringContainsString("\"'\tcmd\"", $csv);
        self::assertStringContainsString('"hello"', $csv);
        self::assertStringNotContainsString('"\'hello"', $csv);
    }

    /**
     * It leaves native numeric values untouched by formula neutralisation.
     *
     * @return void
     */
    public function testDoesNotNeutraliseNativeNumbers(): void
    {
        $csv = (new Csv([]))->exportArray([
            ['amount' => -5, 'rate' => 3.5],
        ]);

        self::assertStringContainsString('"-5"', $csv);
        self::assertStringContainsString('"3.5"', $csv);
        self::assertStringNotContainsString('\'-5', $csv);
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
     * It keeps exporting later rows after a row that filters to empty.
     *
     * @return void
     */
    public function testExportArraySkipsEmptyRowsWithoutStoppingLaterRows(): void
    {
        $exporter = new Csv([]);
        $exporter->withoutHeaders();

        $csv = $exporter->exportArray([
            ['meta' => ['not exportable']],
            ['name' => 'Alice'],
        ]);

        self::assertSame("\"Alice\"\n", $csv);
    }

    /**
     * It falls back to default delimiter and enclosure for invalid config.
     *
     * @return void
     */
    public function testInvalidDelimiterAndEnclosureConfigFallBackToDefaults(): void
    {
        $exporter = new Csv([
            'delimiter' => 123,
            'enclosure' => true,
        ]);

        self::assertSame('"a","b"', $this->invokePrivate($exporter, 'generateRow', ['a', 'b']));
        self::assertSame('"A"', $this->invokePrivate($exporter, 'generateColumns', ['a']));
    }

    /**
     * It supports custom delimiters and enclosures.
     *
     * @return void
     */
    public function testCustomDelimiterAndEnclosureAreUsed(): void
    {
        $exporter = new Csv([
            'delimiter' => ';',
            'enclosure' => '\'',
        ]);

        self::assertSame('\'a\';\'b\'', $this->invokePrivate($exporter, 'generateRow', ['a', 'b']));
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
        $stringable = new class implements \Stringable {
            /**
             * Cast to a display value.
             *
             * @return string
             */
            #[\Override]
            public function __toString(): string
            {
                return 'memo';
            }
        };

        $exporter = new Csv([]);
        $exporter->withoutFields(['secret', '0']);

        $columns = $this->invokePrivate($exporter, 'generateColumns', ['first-name', 'last_name']);
        $row     = $this->invokePrivate($exporter, 'generateRow', ['A', 2, null]);

        // The non-stringable payload is skipped (continue, not break) so the
        // trailing stringable note is still reached, and that note must be
        // cast to its string form rather than stored as the original object.
        $filtered = $this->invokePrivate($exporter, 'filterData', [
            0         => 'zero',
            'name'    => 'Alice',
            'secret'  => 'hidden',
            'payload' => new \stdClass,
            'note'    => $stringable,
        ]);

        self::assertSame('"First Name","Last Name"', $columns);
        self::assertSame('"A","2",""', $row);
        self::assertSame(['name' => 'Alice', 'note' => 'memo'], $filtered);
        self::assertSame('First Name', $this->invokePrivate($exporter, 'convertToWords', 'first-name'));
        self::assertSame('"say ""hi"""', $this->invokePrivate($exporter, 'escapeValue', 'say "hi"'));
    }

    /**
     * It returns no generated columns when headers are disabled.
     *
     * @return void
     */
    public function testGenerateColumnsReturnsEmptyStringWhenHeadersAreDisabled(): void
    {
        $exporter = new Csv([]);
        $exporter->withoutHeaders();

        self::assertSame('', $this->invokePrivate($exporter, 'generateColumns', ['name']));
    }

    /**
     * Invoke a protected method on the exporter for direct assertions.
     *
     * @param  \SineMacula\Exporter\Exporters\Csv  $exporter
     * @param  string  $method
     * @param  mixed  ...$arguments
     * @return mixed
     *
     * @throws \ReflectionException
     */
    private function invokePrivate(Csv $exporter, string $method, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionMethod(Csv::class, $method);

        return $reflection->invokeArgs($exporter, $arguments);
    }
}
