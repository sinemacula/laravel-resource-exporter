<?php

declare(strict_types = 1);

namespace Tests\Unit\Writers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Sinks\StreamSink;
use SineMacula\Exporter\Writers\NdjsonWriter;

/**
 * Constant-memory streaming test for a large hierarchical export.
 *
 * Drives a hierarchical writer with a lazy generator of many items into a
 * file-backed stream and asserts peak memory stays under a generous ceiling
 * independent of the row count - proving the set is streamed one item at a time
 * and never materialised. The assertion is skipped with a note where the
 * runtime cannot reset its peak-memory counter.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(NdjsonWriter::class)]
#[CoversClass(StreamSink::class)]
final class HierarchicalStreamingMemoryTest extends TestCase
{
    /**
     * It streams 100k hierarchical items at bounded peak memory.
     *
     * @return void
     */
    public function testLargeHierarchicalExportStreamsAtBoundedMemory(): void
    {
        if (!function_exists('memory_reset_peak_usage')) {
            self::markTestSkipped('memory_reset_peak_usage() is unavailable on this runtime.');
        }

        $count  = 100000;
        $path   = (string) tempnam(sys_get_temp_dir(), 'ndjson_mem_');
        $handle = fopen($path, 'w+b');

        self::assertIsResource($handle);

        $sink = new StreamSink($handle);

        memory_reset_peak_usage();
        $before = memory_get_usage();

        (new NdjsonWriter)->write($this->items($count), $sink);

        $peak = memory_get_peak_usage() - $before;

        self::assertLessThan(8 * 1024 * 1024, $peak, 'Peak memory grew with the row count - the export was materialised, not streamed.');
        self::assertSame($count, $this->countLines($path), 'The full set was not streamed.');

        fclose($handle);
        @unlink($path);
    }

    /**
     * Lazily yield the given number of hierarchical items.
     *
     * @param  int  $count
     * @return \Generator<int, array<string, mixed>>
     */
    private function items(int $count): \Generator
    {
        for ($i = 1; $i <= $count; $i++) {
            yield [
                'id'    => $i,
                'name'  => 'User ' . $i,
                'email' => 'user' . $i . '@example.test',
                'tags'  => ['alpha', 'beta', 'gamma'],
                'meta'  => ['score' => $i, 'active' => true],
            ];
        }
    }

    /**
     * Count the lines in the written file at constant memory.
     *
     * @param  string  $path
     * @return int
     */
    private function countLines(string $path): int
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return 0;
        }

        $lines = 0;

        while (fgets($handle) !== false) {
            $lines++;
        }

        fclose($handle);

        return $lines;
    }
}
