<?php

declare(strict_types = 1);

namespace Tests\Unit\Writers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Sinks\StringSink;
use SineMacula\Exporter\Writers\JsonWriter;

/**
 * Golden-output tests for the streaming JSON writer.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(JsonWriter::class)]
final class JsonWriterTest extends TestCase
{
    /**
     * It streams a single, comma-separated JSON array of the items.
     *
     * @return void
     */
    public function testGoldenJsonArray(): void
    {
        $output = $this->write([
            ['id' => 1, 'name' => 'Alice', 'active' => true, 'tags' => ['a', 'b'], 'address' => ['city' => 'NY', 'zip' => null]],
            ['id' => 2, 'name' => 'Bob', 'active' => false, 'tags' => [], 'address' => ['city' => 'LA', 'zip' => '90001']],
        ]);

        self::assertSame(
            '[{"id":1,"name":"Alice","active":true,"tags":["a","b"],"address":{"city":"NY","zip":null}},'
            . '{"id":2,"name":"Bob","active":false,"tags":[],"address":{"city":"LA","zip":"90001"}}]',
            $output,
        );
    }

    /**
     * It emits an empty array for an empty set.
     *
     * @return void
     */
    public function testEmptySetProducesEmptyArray(): void
    {
        self::assertSame('[]', $this->write([]));
    }

    /**
     * It wraps a single item in a one-element array.
     *
     * @return void
     */
    public function testSingleItem(): void
    {
        self::assertSame('[{"id":1}]', $this->write([['id' => 1]]));
    }

    /**
     * It leaves UTF-8 and forward slashes unescaped.
     *
     * @return void
     */
    public function testUnicodeAndSlashesLeftUnescaped(): void
    {
        $output = $this->write([
            ['name' => 'Köln', 'url' => 'http://example.test/a/b'],
        ]);

        self::assertStringContainsString('"name":"Köln"', $output);
        self::assertStringContainsString('"url":"http://example.test/a/b"', $output);
    }

    /**
     * It produces a single valid JSON document that decodes to the input.
     *
     * @return void
     */
    public function testStreamsValidJsonDocument(): void
    {
        $items = [
            ['id' => 1, 'name' => 'Alice', 'meta' => ['x' => 1]],
            ['id' => 2, 'name' => 'Bob', 'meta' => ['x' => 2]],
        ];

        $decoded = json_decode($this->write($items), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame($items, $decoded);
    }

    /**
     * It reports the JSON media type.
     *
     * @return void
     */
    public function testMediaType(): void
    {
        self::assertSame('application/json', (new JsonWriter)->mediaType());
    }

    /**
     * Write the given items through a JSON writer and return the buffered
     * output.
     *
     * @param  list<array<array-key, mixed>>  $items
     * @return string
     *
     * @throws \JsonException
     */
    private function write(array $items): string
    {
        $sink = new StringSink;

        (new JsonWriter)->write($items, $sink);

        return $sink->contents();
    }
}
