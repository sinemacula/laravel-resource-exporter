<?php

declare(strict_types = 1);

namespace Tests\Unit\Writers;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Sinks\StringSink;
use SineMacula\Exporter\Writers\TsvWriter;
use Tests\Support\Concerns\ShapesRows;
use Tests\Support\Schema\ArraySchema;

/**
 * Golden-output tests for the streaming TSV writer.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(TsvWriter::class)]
final class TsvWriterTest extends TestCase
{
    use ShapesRows;

    /**
     * It emits tab-delimited rows with the heading and rendered cells.
     *
     * @return void
     */
    public function testGoldenTabDelimitedOutput(): void
    {
        $request = Request::create('/');
        $columns = [
            Column::make('id', 'ID'),
            Column::make('name', 'Name'),
            Column::make('active', 'Active')->boolean('Yes', 'No'),
        ];

        $schema = new ArraySchema($request, $columns);
        $rows   = $this->shapeRows([
            ['id' => 1, 'name' => 'Alice', 'active' => true],
            ['id' => 2, 'name' => 'Bob', 'active' => false],
        ], $columns, $request);

        $sink = new StringSink;
        (new TsvWriter)->write($rows, $schema, $sink);

        self::assertSame(
            "ID\tName\tActive\n"
            . "1\tAlice\tYes\n"
            . "2\tBob\tNo\n",
            $sink->contents(),
        );
    }

    /**
     * It keeps formula-injection escaping on by default.
     *
     * @return void
     */
    public function testFormulaEscapingByDefault(): void
    {
        $request = Request::create('/');
        $columns = [Column::make('name', 'Name')];
        $schema  = new ArraySchema($request, $columns);
        $rows    = $this->shapeRows([['name' => '=cmd']], $columns, $request);

        $sink = new StringSink;
        (new TsvWriter)->write($rows, $schema, $sink);

        self::assertStringContainsString('\'=cmd', $sink->contents());
    }

    /**
     * It reports the tab-separated-values media type.
     *
     * @return void
     */
    public function testMediaType(): void
    {
        self::assertSame('text/tab-separated-values', (new TsvWriter)->mediaType());
    }
}
