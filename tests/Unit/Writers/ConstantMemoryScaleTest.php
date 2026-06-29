<?php

declare(strict_types = 1);

namespace Tests\Unit\Writers;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Schema\CellValue;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\Enums\CellType;
use SineMacula\Exporter\Sinks\StreamSink;
use SineMacula\Exporter\Writers\CsvWriter;
use Tests\Support\Schema\ArraySchema;

/**
 * Headline constant-memory proof for the streaming CSV writer.
 *
 * Streams 10k, 100k and one million synthetic rows from a generator (never the
 * database, never a materialised array) through the CSV writer into a throwaway
 * php://temp stream, and asserts peak memory stays under a single fixed ceiling
 * for every size. Because the same ceiling holds whether ten thousand or a
 * million rows pass through, peak memory is proven not to scale with the row
 * count - the row is shaped, written and discarded one at a time. The full set
 * is then counted back to confirm no rows were dropped. The assertion is
 * skipped with a note where the runtime cannot reset its peak-memory counter.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CsvWriter::class)]
#[CoversClass(StreamSink::class)]
#[Group('scale')]
final class ConstantMemoryScaleTest extends TestCase
{
    /** @var int The fixed peak-memory ceiling that must hold at every row count. */
    private const int MEMORY_CEILING = 24 * 1024 * 1024;

    /**
     * Row counts spanning four orders of magnitude.
     *
     * @return iterable<string, array{int}>
     */
    public static function rowCounts(): iterable
    {
        yield '10k rows' => [10000];
        yield '100k rows' => [100000];
        yield '1m rows' => [1000000];
    }

    /**
     * It streams the given number of rows under a fixed peak-memory ceiling.
     *
     * @param  int  $count
     * @return void
     */
    #[DataProvider('rowCounts')]
    public function testCsvStreamsAtConstantMemoryRegardlessOfRowCount(int $count): void
    {
        if (!function_exists('memory_reset_peak_usage')) {
            self::markTestSkipped('memory_reset_peak_usage() is unavailable on this runtime.');
        }

        $request = Request::create('/');
        $columns = [
            Column::make('id', 'ID'),
            Column::make('name', 'Name'),
            Column::make('score', 'Score')->number(2),
        ];
        $schema = new ArraySchema($request, $columns);
        $handle = fopen('php://temp', 'w+b');

        self::assertIsResource($handle);

        memory_reset_peak_usage();
        $before = memory_get_usage();

        (new CsvWriter)->write($this->rows($count), $schema, new StreamSink($handle));

        $peak = memory_get_peak_usage() - $before;

        self::assertLessThan(self::MEMORY_CEILING, $peak, 'Peak memory grew with the row count - the export was materialised, not streamed.');
        self::assertSame($count + 1, $this->countLines($handle), 'The full set was not streamed (heading plus every data row expected).');

        fclose($handle);
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

    /**
     * Count the lines written to the stream at constant memory.
     *
     * @param  resource  $handle
     * @return int
     */
    private function countLines($handle): int // phpcs:ignore SineMaculaLaravel.TypeHints.ParameterTypeHint.MissingNativeTypeHint
    {
        rewind($handle);

        $lines = 0;

        while (fgets($handle) !== false) {
            $lines++;
        }

        return $lines;
    }
}
