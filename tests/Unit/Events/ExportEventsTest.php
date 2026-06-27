<?php

declare(strict_types = 1);

namespace Tests\Unit\Events;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Events\ExportCompleted;
use SineMacula\Exporter\Events\ExportFailed;
use SineMacula\Exporter\Events\ExportStarting;
use SineMacula\Exporter\Events\RowsExported;

/**
 * Tests the export lifecycle events.
 *
 * The events are the pinned audit and progress surface of the export pipeline:
 * starting before any rows are read, periodic row-count progress, the completed
 * audit record (with the optional queued-delivery fields), and the failure
 * record carrying the underlying exception. These tests assert each event
 * carries its declared payload unchanged.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExportCompleted::class)]
#[CoversClass(ExportFailed::class)]
#[CoversClass(ExportStarting::class)]
#[CoversClass(RowsExported::class)]
final class ExportEventsTest extends TestCase
{
    /**
     * It carries the up-front context on the starting event.
     *
     * @return void
     */
    public function testStartingCarriesContext(): void
    {
        $event = new ExportStarting('csv', 'exports', 'reports/users.csv', 42);

        self::assertSame('csv', $event->format);
        self::assertSame('exports', $event->disk);
        self::assertSame('reports/users.csv', $event->path);
        self::assertSame(42, $event->actorId);
        self::assertNull((new ExportStarting('csv', 'exports', 'p'))->actorId);
    }

    /**
     * It carries the running row count on the progress event.
     *
     * @return void
     */
    public function testRowsExportedCarriesProgress(): void
    {
        $event = new RowsExported(2500, 'xlsx', 'exports', 'reports/users.xlsx');

        self::assertSame(2500, $event->rows);
        self::assertSame('xlsx', $event->format);
        self::assertSame('exports', $event->disk);
        self::assertSame('reports/users.xlsx', $event->path);
    }

    /**
     * It carries the full audit payload, including the queued-delivery fields.
     *
     * @return void
     */
    public function testCompletedCarriesAuditPayload(): void
    {
        $at    = Carbon::parse('2026-06-27 10:00:00');
        $event = new ExportCompleted('user-1', 1000, 'users', 'csv', $at, 'exports', 'reports/users.csv', 'https://signed.test/u');

        self::assertSame('user-1', $event->actorId);
        self::assertSame(1000, $event->rowCount);
        self::assertSame('users', $event->filename);
        self::assertSame('csv', $event->format);
        self::assertSame($at, $event->completedAt);
        self::assertSame('exports', $event->disk);
        self::assertSame('reports/users.csv', $event->path);
        self::assertSame('https://signed.test/u', $event->url);

        $streamed = new ExportCompleted(null, 5, null, 'csv', $at);

        self::assertNull($streamed->actorId);
        self::assertNull($streamed->disk);
        self::assertNull($streamed->path);
        self::assertNull($streamed->url);
    }

    /**
     * It carries the failure context and the underlying exception.
     *
     * @return void
     */
    public function testFailedCarriesException(): void
    {
        $cause = new \RuntimeException('boom');
        $event = new ExportFailed('xlsx', 'exports', 'reports/users.xlsx', 7, $cause);

        self::assertSame('xlsx', $event->format);
        self::assertSame('exports', $event->disk);
        self::assertSame('reports/users.xlsx', $event->path);
        self::assertSame(7, $event->actorId);
        self::assertSame($cause, $event->exception);
    }
}
