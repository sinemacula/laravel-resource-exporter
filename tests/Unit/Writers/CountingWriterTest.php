<?php

declare(strict_types = 1);

namespace Tests\Unit\Writers;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Sinks\StringSink;
use SineMacula\Exporter\Writers\CountingWriter;
use SineMacula\Exporter\Writers\CsvWriter;
use Tests\Support\V3\Concerns\ShapesRows;
use Tests\Support\V3\Schema\ArraySchema;

/**
 * Tests the row-counting writer decorator.
 *
 * Wraps any writer transparently - delegating the media type and the write -
 * while invoking a callback with the running row count after each data row, so
 * the fluent builder learns how many rows an export emitted without buffering
 * the set or re-counting the source.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CountingWriter::class)]
final class CountingWriterTest extends TestCase
{
    use ShapesRows;

    /**
     * It delegates the media type to the wrapped writer.
     *
     * @return void
     */
    public function testDelegatesTheMediaType(): void
    {
        $writer = new CountingWriter(new CsvWriter, static fn (): null => null);

        self::assertSame('text/csv', $writer->mediaType());
    }

    /**
     * It reports the running row count as it writes.
     *
     * @return void
     */
    public function testReportsTheRunningRowCount(): void
    {
        $count  = 0;
        $writer = new CountingWriter(new CsvWriter, static function (int $rows) use (&$count): void {
            $count = $rows;
        });
        $request = Request::create('/');
        $columns = [Column::make('id', 'ID')];
        $rows    = $this->shapeRows([['id' => 1], ['id' => 2], ['id' => 3]], $columns, $request);
        $sink    = new StringSink;

        $writer->write($rows, new ArraySchema($request, $columns), $sink);

        self::assertSame(3, $count);
        self::assertSame("ID\n1\n2\n3\n", $sink->contents());
    }
}
