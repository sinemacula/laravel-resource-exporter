<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Engine;
use SineMacula\Exporter\Exceptions\InvalidExportSchema;
use SineMacula\Exporter\Exceptions\NoTabularRepresentation;
use SineMacula\Exporter\Http\ExportNegotiator;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\Enums\Strictness;
use SineMacula\Exporter\Schema\WarningCollector;
use SineMacula\Exporter\Sinks\StringSink;
use SineMacula\Exporter\Writers\CsvWriter;
use Tests\Support\V3\ArraySource;
use Tests\Support\V3\Resources\PlainUserResource;
use Tests\Support\V3\Schema\FlexibleSchema;

/**
 * Tests for the engine's strictness modes.
 *
 * Preflight (the default) validates the schema before any bytes commit and
 * fails fast on an invalid column or cast - a streamed response cannot emit a
 * clean error mid-body, so falsifiable failures must happen up front. Lenient
 * instead degrades the export: a bad column is skipped and a throwing cell is
 * blanked, each recorded as a warning rather than thrown. The missing-tabular
 * representation still yields a hard 406 under either mode.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Engine::class)]
#[CoversClass(InvalidExportSchema::class)]
#[CoversClass(WarningCollector::class)]
#[CoversClass(ExportNegotiator::class)]
#[CoversClass(NoTabularRepresentation::class)]
final class EngineStrictnessTest extends TestCase
{
    /**
     * Preflight fails fast on an unresolvable cast before any bytes are sent.
     *
     * @return void
     */
    public function testPreflightFailsFastOnAnInvalidCastBeforeStreaming(): void
    {
        $columns = [Column::make('id', 'ID')->cast('does-not-exist')];
        $schema  = new FlexibleSchema(self::request(), $columns);
        $sink    = new StringSink;

        try {
            (new Engine)->export(new ArraySource([['id' => 1]]), $schema, self::request(), new CsvWriter, $sink);
            self::fail('Expected preflight to reject the unresolvable cast.');
        } catch (InvalidExportSchema $exception) {
            self::assertStringContainsString('does-not-exist', $exception->getMessage());
        }

        self::assertSame('', $sink->contents(), 'No bytes may be written when preflight rejects the schema.');
    }

    /**
     * Preflight fails fast on an empty column key before any bytes are written.
     *
     * @return void
     */
    public function testPreflightFailsFastOnAnEmptyColumnKey(): void
    {
        $columns = [Column::make('', 'Blank'), Column::make('id', 'ID')];
        $schema  = new FlexibleSchema(self::request(), $columns);
        $sink    = new StringSink;

        $this->expectException(InvalidExportSchema::class);

        try {
            (new Engine)->export(new ArraySource([['id' => 1]]), $schema, self::request(), new CsvWriter, $sink);
        } finally {
            self::assertSame('', $sink->contents());
        }
    }

    /**
     * Lenient skips an invalid column and warns, exporting the rest.
     *
     * @return void
     */
    public function testLenientSkipsAnInvalidColumnAndCollectsAWarning(): void
    {
        $columns = [
            Column::make('id', 'ID'),
            Column::make('broken', 'Broken')->cast('does-not-exist'),
            Column::make('name', 'Name'),
        ];
        $schema   = new FlexibleSchema(self::request(), $columns, strictness: Strictness::LENIENT);
        $sink     = new StringSink;
        $warnings = new WarningCollector;

        (new Engine)->export(new ArraySource([['id' => 1, 'name' => 'Ada']]), $schema, self::request(), new CsvWriter, $sink, $warnings);

        self::assertSame("ID,Name\n1,Ada\n", $sink->contents());
        self::assertNotEmpty($warnings->all());
        self::assertStringContainsString('Column [broken] skipped', $warnings->all()[0]);
    }

    /**
     * Lenient blanks a cell whose resolution throws and records a warning.
     *
     * @return void
     */
    public function testLenientBlanksAThrowingCellAndCollectsAWarning(): void
    {
        $columns = [
            Column::make('id', 'ID'),
            Column::make('boom', 'Boom')->resolveUsing(self::raise(...)),
        ];
        $schema   = new FlexibleSchema(self::request(), $columns, strictness: Strictness::LENIENT);
        $sink     = new StringSink;
        $warnings = new WarningCollector;

        (new Engine)->export(new ArraySource([['id' => 1]]), $schema, self::request(), new CsvWriter, $sink, $warnings);

        self::assertSame("ID,Boom\n1,\n", $sink->contents());
        self::assertNotEmpty($warnings->all());
        self::assertStringContainsString('kaboom', $warnings->all()[0]);
    }

    /**
     * The missing-tabular-representation path still yields a 406.
     *
     * @return void
     */
    public function testMissingTabularRepresentationStillYields406(): void
    {
        $resource = new PlainUserResource(new \stdClass);
        $request  = Request::create('/', server: ['HTTP_ACCEPT' => 'text/csv']);

        $this->expectException(NoTabularRepresentation::class);

        (new ExportNegotiator)->item($resource, $request);
    }

    /**
     * Always throw, standing in for a column resolver that fails per row.
     *
     * @return mixed
     *
     * @throws \RuntimeException
     */
    private static function raise(): mixed
    {
        throw new \RuntimeException('kaboom');
    }

    /**
     * Build a bare request for the engine run.
     *
     * @return \Illuminate\Http\Request
     */
    private static function request(): Request
    {
        return Request::create('/');
    }
}
