<?php

declare(strict_types = 1);

namespace Tests\Unit\Writers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Sinks\StringSink;
use SineMacula\Exporter\Writers\NdjsonWriter;

/**
 * Golden-output tests for the streaming NDJSON writer.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(NdjsonWriter::class)]
final class NdjsonWriterTest extends TestCase
{
    /**
     * It streams one compact JSON object per line.
     *
     * @return void
     */
    public function testGoldenOneObjectPerLine(): void
    {
        $output = $this->write(new NdjsonWriter, [
            ['id' => 1, 'name' => 'Alice', 'tags' => ['a', 'b']],
            ['id' => 2, 'name' => 'Köln', 'tags' => []],
        ]);

        self::assertSame(
            '{"id":1,"name":"Alice","tags":["a","b"]}' . "\n"
            . '{"id":2,"name":"Köln","tags":[]}' . "\n",
            $output,
        );
    }

    /**
     * It writes nothing for an empty set.
     *
     * @return void
     */
    public function testEmptySetProducesNoOutput(): void
    {
        self::assertSame('', $this->write(new NdjsonWriter, []));
    }

    /**
     * It emits a trailing line terminator after every record, so each line is a
     * valid JSON object on its own.
     *
     * @return void
     */
    public function testEachLineIsAValidJsonObject(): void
    {
        $items = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
            ['id' => 3, 'name' => 'Carol'],
        ];

        $output = $this->write(new NdjsonWriter, $items);
        $lines  = explode("\n", rtrim($output, "\n"));

        self::assertCount(3, $lines);

        foreach ($lines as $index => $line) {
            self::assertSame($items[$index], json_decode($line, true, flags: JSON_THROW_ON_ERROR));
        }
    }

    /**
     * It honours a configured end-of-line sequence.
     *
     * @return void
     */
    public function testCustomEndOfLine(): void
    {
        $output = $this->write(new NdjsonWriter(endOfLine: "\r\n"), [
            ['a' => 1],
            ['b' => 2],
        ]);

        self::assertSame('{"a":1}' . "\r\n" . '{"b":2}' . "\r\n", $output);
    }

    /**
     * It reports the NDJSON media type.
     *
     * @return void
     */
    public function testMediaType(): void
    {
        self::assertSame('application/x-ndjson', (new NdjsonWriter)->mediaType());
    }

    /**
     * Write the given items through the writer and return the buffered output.
     *
     * @param  \SineMacula\Exporter\Writers\NdjsonWriter  $writer
     * @param  list<array<array-key, mixed>>  $items
     * @return string
     *
     * @throws \JsonException
     */
    private function write(NdjsonWriter $writer, array $items): string
    {
        $sink = new StringSink;

        $writer->write($items, $sink);

        return $sink->contents();
    }
}
