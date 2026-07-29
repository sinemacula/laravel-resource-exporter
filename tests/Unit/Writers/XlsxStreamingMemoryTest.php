<?php

declare(strict_types = 1);

namespace Tests\Unit\Writers;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Schema\CellValue;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\Enums\CellType;
use SineMacula\Exporter\Sinks\TempFileSink;
use SineMacula\Exporter\Writers\XlsxWriter;
use Tests\Support\Concerns\ReadsXlsx;
use Tests\Support\Schema\ArraySchema;

/**
 * Constant-memory streaming test for a large XLSX export.
 *
 * Drives the writer with a lazy generator of many typed-cell rows into a
 * temp-file sink and asserts peak memory stays under a generous ceiling
 * independent of the row count - proving OpenSpout streams the workbook to disk
 * one row at a time rather than buffering it. The full set is then read back to
 * confirm no rows were dropped. The assertion is skipped with a note where the
 * runtime cannot reset its peak-memory counter.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(XlsxWriter::class)]
#[CoversClass(TempFileSink::class)]
final class XlsxStreamingMemoryTest extends TestCase
{
    use ReadsXlsx;

    /**
     * It streams a large tabular XLSX export at bounded peak memory.
     *
     * @return void
     *
     * @throws \OpenSpout\Common\Exception\IOException
     * @throws \OpenSpout\Writer\Exception\WriterNotOpenedException
     */
    public function testLargeXlsxExportStreamsAtBoundedMemory(): void
    {
        if (!function_exists('memory_reset_peak_usage')) {
            self::markTestSkipped('memory_reset_peak_usage() is unavailable on this runtime.');
        }

        $count   = 20000;
        $request = Request::create('/');
        $columns = [
            Column::make('id', 'ID'),
            Column::make('name', 'Name'),
            Column::make('score', 'Score')->number(2),
        ];
        $schema = new ArraySchema($request, $columns);
        $sink   = new TempFileSink;

        memory_reset_peak_usage();
        $before = memory_get_usage();

        (new XlsxWriter)->write($this->rows($count), $schema, $sink);

        $peak = memory_get_peak_usage() - $before;

        self::assertLessThan(24 * 1024 * 1024, $peak, 'Peak memory grew with the row count - the workbook was buffered, not streamed.');

        $read = $this->readWorkbook($sink->path());

        self::assertCount($count + 1, $read, 'The full set was not streamed (heading plus every data row expected).');

        @unlink($sink->path());
    }

    /**
     * Lazily yield the given number of shaped, typed-cell rows.
     *
     * @param  int  $count
     * @return \Generator<int, array<string, \SineMacula\Exporter\Schema\CellValue>>
     */
    private function rows(int $count): \Generator
    {
        for ($i = 1; $i <= $count; $i++) {
            yield [
                'id'    => new CellValue($i, CellType::INTEGER),
                'name'  => new CellValue('User ' . $i, CellType::STRING),
                'score' => new CellValue($i + 0.5, CellType::FLOAT, '0.00'),
            ];
        }
    }
}
