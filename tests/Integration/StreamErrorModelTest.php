<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Engine;
use SineMacula\Exporter\Events\StreamExportFailed;
use SineMacula\Exporter\Http\ExportNegotiator;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\TabularSchema;
use SineMacula\Exporter\Sources\ConnectionAwareSource;
use SineMacula\Exporter\Writers\CountingWriter;
use SineMacula\Exporter\Writers\JsonWriter;
use SineMacula\Exporter\Writers\Truncation;
use Tests\Support\V3\ArraySource;
use Tests\Support\V3\ExporterTestCase;
use Tests\Support\V3\Schema\FlexibleSchema;

/**
 * End-to-end tests for the streamed export error model.
 *
 * Drives the negotiator's streamed tabular path to prove the whole contract:
 * when an export throws after the response has begun, the partial rows survive,
 * the writer flushes a documented truncation marker, a StreamExportFailed event
 * fires carrying the row context, and the stream stops cleanly without the 200
 * response becoming an error. It also proves a client disconnect is honoured -
 * the negotiator's injected abort signal stops the source early, with no marker
 * and no failure event, because an abandoned export is not a failed one.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExportNegotiator::class)]
#[CoversClass(ConnectionAwareSource::class)]
#[CoversClass(Engine::class)]
#[CoversClass(CountingWriter::class)]
#[CoversClass(StreamExportFailed::class)]
final class StreamErrorModelTest extends ExporterTestCase
{
    /**
     * A mid-stream failure marks the body and fires the failure event.
     *
     * @return void
     */
    public function testMidStreamFailureFlushesMarkerAndFiresFailureEvent(): void
    {
        Event::fake([StreamExportFailed::class]);

        $request  = Request::create('/export', 'GET', server: ['HTTP_ACCEPT' => 'text/csv']);
        $response = (new ExportNegotiator)->streamExport(
            new ArraySource([
                ['id' => 1, 'value' => 'a'],
                ['id' => 2, 'value' => 'b'],
                ['id' => 3, 'value' => 'c'],
            ]),
            $this->throwingSchema($request),
            'csv',
            $request,
        );

        self::assertSame(200, $response->getStatusCode());

        $body = $this->streamToString($response);

        self::assertStringContainsString("ID,Value\n1,a\n", $body, 'The rows streamed before the failure must survive.');
        self::assertStringContainsString(Truncation::CSV_FIELD, $body, 'The body must carry the documented truncation marker.');

        Event::assertDispatched(StreamExportFailed::class, static fn (StreamExportFailed $event): bool => $event->format === 'csv'
                && $event->rowsWritten                                                                                   === 1
                && $event->actorId                                                                                       === null
                && $event->exception->getMessage()                                                                       === 'mid-stream failure');
    }

    /**
     * A mid-stream failure on the hierarchical path also marks and fires.
     *
     * @return void
     */
    public function testHierarchicalMidStreamFailureFlushesMarkerAndFires(): void
    {
        Event::fake([StreamExportFailed::class]);

        $request = Request::create('/export', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);

        $response = (new ExportNegotiator)->streamHierarchical(
            new ArraySource([['id' => 1], ['id' => 2], ['id' => 3]]),
            static function (mixed $item): array {
                if (data_get($item, 'id') === 2) {
                    throw new \RuntimeException('hierarchical failure');
                }

                return ['id' => data_get($item, 'id')];
            },
            new JsonWriter,
            'json',
            $request,
        );

        self::assertSame(200, $response->getStatusCode());

        $body = $this->streamToString($response);

        self::assertStringContainsString('"id":1', $body, 'The rows streamed before the failure must survive.');
        self::assertStringContainsString(Truncation::JSON_KEY, $body, 'The body must carry the documented truncation marker.');

        Event::assertDispatched(StreamExportFailed::class, static fn (StreamExportFailed $event): bool => $event->format === 'json'
                && $event->rowsWritten                                                                                   === 1
                && $event->exception->getMessage()                                                                       === 'hierarchical failure');
    }

    /**
     * A client disconnect stops the stream early, cleanly and unmarked.
     *
     * @return void
     */
    public function testClientDisconnectStopsTheStreamEarlyWithoutFailing(): void
    {
        Event::fake([StreamExportFailed::class]);

        $calls      = 0;
        $negotiator = new ExportNegotiator(abortSignal: static function () use (&$calls): bool {
            return ++$calls > 2;
        });

        $request  = Request::create('/export', 'GET', server: ['HTTP_ACCEPT' => 'text/csv']);
        $response = $negotiator->streamExport(
            new ArraySource([['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4], ['id' => 5]]),
            new FlexibleSchema($request, [Column::make('id', 'ID')]),
            'csv',
            $request,
        );

        $body  = $this->streamToString($response);
        $lines = array_values(array_filter(explode("\n", $body), static fn (string $line): bool => $line !== ''));

        self::assertSame(['ID', '1', '2'], $lines, 'Only the rows read before the disconnect may be streamed.');
        self::assertStringNotContainsString(Truncation::CSV_FIELD, $body, 'A clean disconnect is not a truncation.');

        Event::assertNotDispatched(StreamExportFailed::class);
    }

    /**
     * Build a schema whose second row throws when its value resolves.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \SineMacula\Exporter\Schema\TabularSchema
     */
    private function throwingSchema(Request $request): TabularSchema
    {
        return new FlexibleSchema($request, [
            Column::make('id', 'ID'),
            Column::make('value', 'Value')->resolveUsing(static function (array|object $item): mixed {
                if (data_get($item, 'id') === 2) {
                    throw new \RuntimeException('mid-stream failure');
                }

                return data_get($item, 'value');
            }),
        ]);
    }
}
