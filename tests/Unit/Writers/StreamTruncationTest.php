<?php

declare(strict_types = 1);

namespace Tests\Unit\Writers;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Schema\CellValue;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\Enums\CellType;
use SineMacula\Exporter\Sinks\StringSink;
use SineMacula\Exporter\Writers\CsvWriter;
use SineMacula\Exporter\Writers\JsonWriter;
use SineMacula\Exporter\Writers\NdjsonWriter;
use SineMacula\Exporter\Writers\Truncation;
use SineMacula\Exporter\Writers\TsvWriter;
use SineMacula\Exporter\Writers\XmlWriter;
use Tests\Support\V3\Schema\ArraySchema;

/**
 * Tests the documented truncation marker each streaming writer flushes.
 *
 * A streamed response commits its 200 status and first bytes before a failure
 * can occur mid-stream, so it cannot become a clean error. Instead each writer
 * appends a final, clearly-marked record - a CSV/TSV field, a JSON/NDJSON
 * element, or an XML node - using its live internal state, flushes it, then
 * re-throws so the engine can surface the failure. These tests prove the marker
 * is present, the partial rows that preceded it survive, the structured formats
 * stay parseable, and the original exception still propagates.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CsvWriter::class)]
#[CoversClass(TsvWriter::class)]
#[CoversClass(JsonWriter::class)]
#[CoversClass(NdjsonWriter::class)]
#[CoversClass(XmlWriter::class)]
#[CoversClass(Truncation::class)]
final class StreamTruncationTest extends TestCase
{
    /**
     * CSV finishes the partial output with a marked record and re-throws.
     *
     * @return void
     */
    public function testCsvFlushesATruncationRecordAndRethrows(): void
    {
        $sink = new StringSink;

        $this->assertThrows(static function () use ($sink): void {
            (new CsvWriter)->write(
                self::tabularRowsThenThrow(),
                new ArraySchema(Request::create('/'), [Column::make('id', 'ID')]),
                $sink,
            );
        });

        $output = $sink->contents();

        self::assertStringContainsString("ID\n1\n", $output, 'The rows written before the failure must survive.');
        self::assertStringContainsString(Truncation::CSV_FIELD, $output, 'A truncation record must be flushed.');
        self::assertStringEndsWith(Truncation::CSV_FIELD . "\n", $output);
    }

    /**
     * TSV shares the CSV pipeline, so it flushes the same marker.
     *
     * @return void
     */
    public function testTsvFlushesATruncationRecordAndRethrows(): void
    {
        $sink = new StringSink;

        $this->assertThrows(static function () use ($sink): void {
            (new TsvWriter)->write(
                self::tabularRowsThenThrow(),
                new ArraySchema(Request::create('/'), [Column::make('id', 'ID')]),
                $sink,
            );
        });

        self::assertStringContainsString(Truncation::CSV_FIELD, $sink->contents());
    }

    /**
     * JSON closes the array with a marker element and stays valid JSON.
     *
     * @return void
     */
    public function testJsonClosesWithAMarkerElementAndRethrows(): void
    {
        $sink = new StringSink;

        $this->assertThrows(static function () use ($sink): void {
            (new JsonWriter)->write(self::hierarchicalRowsThenThrow(), $sink);
        });

        $decoded = json_decode($sink->contents(), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertSame(['id' => 1], $decoded[0], 'The row written before the failure must survive.');
        self::assertSame(Truncation::REASON, $decoded[1][Truncation::JSON_KEY] ?? null);
    }

    /**
     * JSON marks a truncation that happens before any row is written.
     *
     * @return void
     */
    public function testJsonMarksAFailureBeforeAnyRow(): void
    {
        $sink = new StringSink;

        $this->assertThrows(static function () use ($sink): void {
            (new JsonWriter)->write(self::throwImmediately(), $sink);
        });

        $decoded = json_decode($sink->contents(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([[Truncation::JSON_KEY => Truncation::REASON]], $decoded);
    }

    /**
     * NDJSON appends a marker line and stays line-delimited JSON.
     *
     * @return void
     */
    public function testNdjsonAppendsAMarkerLineAndRethrows(): void
    {
        $sink = new StringSink;

        $this->assertThrows(static function () use ($sink): void {
            (new NdjsonWriter)->write(self::hierarchicalRowsThenThrow(), $sink);
        });

        $contents = $sink->contents();
        $lines    = array_values(array_filter(explode("\n", $contents), static fn (string $line): bool => $line !== ''));

        self::assertSame(['id' => 1], json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(Truncation::REASON, json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR)[Truncation::JSON_KEY]);

        // The marker line is the encoded object followed by the end-of-line, in
        // that order - never the newline first and never without a terminator.
        self::assertSame(
            '{"id":1}' . "\n"
            . '{"' . Truncation::JSON_KEY . '":"' . Truncation::REASON . '"}' . "\n",
            $contents,
        );
    }

    /**
     * XML closes the document with a marker element and stays well-formed.
     *
     * @return void
     */
    public function testXmlClosesWithAMarkerElementAndRethrows(): void
    {
        $sink = new StringSink;

        $this->assertThrows(static function () use ($sink): void {
            (new XmlWriter)->write(self::hierarchicalRowsThenThrow(), $sink);
        });

        $output = $sink->contents();

        self::assertStringContainsString('<' . Truncation::XML_ELEMENT . '>' . Truncation::REASON . '</' . Truncation::XML_ELEMENT . '>', $output);
        self::assertStringEndsWith("</data>\n", $output);
        self::assertInstanceOf(\SimpleXMLElement::class, simplexml_load_string($output), 'The truncated XML must stay well-formed.');
    }

    /**
     * Assert the given writer call re-throws the original mid-stream exception.
     *
     * @param  \Closure(): void  $call
     * @return void
     */
    private function assertThrows(\Closure $call): void
    {
        try {
            $call();
        } catch (\RuntimeException $exception) {
            self::assertSame('mid-stream failure', $exception->getMessage());

            return;
        }

        self::fail('The writer must re-throw the mid-stream exception after flushing the marker.');
    }

    /**
     * Yield one shaped tabular row, then throw mid-stream.
     *
     * @return \Generator<int, array<string, \SineMacula\Exporter\Schema\CellValue>>
     *
     * @throws \RuntimeException
     */
    private static function tabularRowsThenThrow(): \Generator
    {
        yield ['id' => new CellValue(1, CellType::INTEGER)];

        throw new \RuntimeException('mid-stream failure');
    }

    /**
     * Yield one hierarchical row, then throw mid-stream.
     *
     * @return \Generator<int, array<string, mixed>>
     *
     * @throws \RuntimeException
     */
    private static function hierarchicalRowsThenThrow(): \Generator
    {
        yield ['id' => 1];

        throw new \RuntimeException('mid-stream failure');
    }

    /**
     * Throw before yielding any row.
     *
     * @return \Generator<int, array<string, mixed>>
     *
     * @throws \RuntimeException
     */
    private static function throwImmediately(): \Generator
    {
        yield from [];

        throw new \RuntimeException('mid-stream failure');
    }
}
