<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Events\ExportCompleted;
use SineMacula\Exporter\Exceptions\NoTabularRepresentation;
use SineMacula\Exporter\Exceptions\RowLimitExceeded;
use SineMacula\Exporter\ResourceExport;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\V3\ExporterTestCase;
use Tests\Support\V3\Models\User;
use Tests\Support\V3\Resources\PlainUserResource;
use Tests\Support\V3\Resources\UserResource;

/**
 * Integration tests for the query-aware paginate-or-stream endpoint helper.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ResourceExport::class)]
final class ResourceExportTest extends ExporterTestCase
{
    /**
     * It returns a single paginated page of JSON for a JSON request.
     *
     * @return void
     */
    public function testJsonRequestReturnsASinglePaginatedPage(): void
    {
        $this->seedUsers(25);

        $response = ResourceExport::forQuery(User::query(), UserResource::class)
            ->perPage(10)
            ->paginatedJsonOrStreamedExport($this->jsonRequest());

        self::assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true);

        self::assertIsArray($payload);
        self::assertCount(10, $payload['data']);
        self::assertSame(25, $payload['meta']['total']);
    }

    /**
     * It streams the full dataset - more rows than one page - for an export
     * request.
     *
     * @return void
     */
    public function testExportRequestStreamsMoreThanOnePage(): void
    {
        $this->seedUsers(25);

        $response = ResourceExport::forQuery(User::query(), UserResource::class)
            ->perPage(10)
            ->chunk(10)
            ->paginatedJsonOrStreamedExport($this->exportRequest());

        self::assertInstanceOf(StreamedResponse::class, $response);

        $lines = $this->dataLines($this->streamToString($response));

        self::assertGreaterThan(10, count($lines));
        self::assertCount(25, $lines);
    }

    /**
     * It enforces the row cap before any bytes are streamed.
     *
     * @return void
     */
    public function testRowCapIsEnforcedBeforeStreaming(): void
    {
        $this->seedUsers(5);

        $this->expectException(RowLimitExceeded::class);

        ResourceExport::forQuery(User::query(), UserResource::class)
            ->maxRows(2)
            ->paginatedJsonOrStreamedExport($this->exportRequest());
    }

    /**
     * It lifts the cap with unlimited().
     *
     * @return void
     */
    public function testUnlimitedLiftsTheCap(): void
    {
        $this->seedUsers(5);

        $response = ResourceExport::forQuery(User::query(), UserResource::class)
            ->maxRows(2)
            ->unlimited()
            ->paginatedJsonOrStreamedExport($this->exportRequest());

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertCount(5, $this->dataLines($this->streamToString($response)));
    }

    /**
     * It streams a large export at bounded peak memory.
     *
     * The keyset source hydrates one chunk at a time, so peak memory must not
     * scale with the row count. The ceiling is deliberately generous; if this
     * proves environment-flaky it may be marked skipped without affecting the
     * functional guarantees proven above.
     *
     * @return void
     */
    public function testLargeExportStreamsAtBoundedPeakMemory(): void
    {
        $this->seedUsers(4000);

        $response = ResourceExport::forQuery(User::query(), UserResource::class)
            ->chunk(500)
            ->unlimited()
            ->paginatedJsonOrStreamedExport($this->exportRequest());

        self::assertInstanceOf(StreamedResponse::class, $response);

        $before = memory_get_usage(true);
        memory_reset_peak_usage();

        $lines = $this->dataLines($this->streamToString($response));

        $delta = memory_get_peak_usage(true) - $before;

        self::assertCount(4000, $lines);
        self::assertLessThan(48 * 1024 * 1024, $delta, 'Streaming peak memory grew unexpectedly.');
    }

    /**
     * It fires the audit hook with the pinned payload once the stream finishes.
     *
     * @return void
     */
    public function testAuditHookFiresWithThePinnedPayload(): void
    {
        $this->seedUsers(3);

        $captured = null;

        $response = ResourceExport::forQuery(User::query(), UserResource::class)
            ->auditUsing(static function (array $payload) use (&$captured): void {
                $captured = $payload;
            })
            ->paginatedJsonOrStreamedExport($this->exportRequest());

        self::assertInstanceOf(StreamedResponse::class, $response);

        // The audit fires from the stream's completion callback, so drain it.
        $this->streamToString($response);

        self::assertIsArray($captured);
        self::assertSame(3, $captured['row_count']);
        self::assertSame('csv', $captured['format']);
        self::assertArrayHasKey('actor_id', $captured);
        self::assertArrayHasKey('filename', $captured);
    }

    /**
     * It returns 406 when the export resource declares no tabular schema.
     *
     * @return void
     */
    public function testExportWithoutATabularSchemaYields406(): void
    {
        $this->seedUsers(2);

        $this->expectException(NoTabularRepresentation::class);

        ResourceExport::forQuery(User::query(), PlainUserResource::class)
            ->unlimited()
            ->paginatedJsonOrStreamedExport($this->exportRequest());
    }

    /**
     * It paginates by the built-in default page size of fifteen.
     *
     * @return void
     */
    public function testPerPageDefaultsToFifteen(): void
    {
        $this->seedUsers(20);

        $response = ResourceExport::forQuery(User::query(), UserResource::class)
            ->paginatedJsonOrStreamedExport($this->jsonRequest());

        $payload = json_decode((string) $response->getContent(), true);

        self::assertIsArray($payload);
        self::assertCount(15, $payload['data']);
        self::assertSame(15, $payload['meta']['per_page']);
    }

    /**
     * It reads the configured page size, casting a numeric string to an int.
     *
     * @return void
     */
    public function testPerPageReadsTheConfiguredNumericString(): void
    {
        $this->seedUsers(20);

        Config::set('exporter.negotiation.per_page', '9');

        $response = ResourceExport::forQuery(User::query(), UserResource::class)
            ->paginatedJsonOrStreamedExport($this->jsonRequest());

        $payload = json_decode((string) $response->getContent(), true);

        self::assertIsArray($payload);
        self::assertCount(9, $payload['data']);
        self::assertSame(9, $payload['meta']['per_page']);
    }

    /**
     * It streams when the row count exactly equals the configured cap.
     *
     * @return void
     */
    public function testRowCapAllowsExactlyTheMaximum(): void
    {
        $this->seedUsers(5);

        $response = ResourceExport::forQuery(User::query(), UserResource::class)
            ->maxRows(5)
            ->paginatedJsonOrStreamedExport($this->exportRequest());

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertCount(5, $this->dataLines($this->streamToString($response)));
    }

    /**
     * It dispatches the pinned ExportCompleted event with an integer actor id.
     *
     * @return void
     */
    public function testStreamedExportDispatchesExportCompletedWithIntegerActor(): void
    {
        Event::fake([ExportCompleted::class]);

        $this->seedUsers(3);

        $response = ResourceExport::forQuery(User::query(), UserResource::class)
            ->paginatedJsonOrStreamedExport($this->exportRequestAs(42));

        self::assertInstanceOf(StreamedResponse::class, $response);

        $this->streamToString($response);

        Event::assertDispatched(ExportCompleted::class, static fn (ExportCompleted $event): bool => $event->actorId === 42
            && $event->rowCount                                                                                     === 3
            && $event->format                                                                                       === 'csv'
            && $event->filename                                                                                     === 'users');
    }

    /**
     * It resolves a string actor identifier into the audit payload.
     *
     * @return void
     */
    public function testStreamedExportResolvesAStringActorIdentifier(): void
    {
        Event::fake([ExportCompleted::class]);

        $this->seedUsers(2);

        $response = ResourceExport::forQuery(User::query(), UserResource::class)
            ->paginatedJsonOrStreamedExport($this->exportRequestAs('user-7'));

        self::assertInstanceOf(StreamedResponse::class, $response);

        $this->streamToString($response);

        Event::assertDispatched(ExportCompleted::class, static fn (ExportCompleted $event): bool => $event->actorId === 'user-7');
    }

    /**
     * It records a null actor id when the request carries no user.
     *
     * @return void
     */
    public function testStreamedExportRecordsNullActorWithoutAUser(): void
    {
        Event::fake([ExportCompleted::class]);

        $this->seedUsers(2);

        $response = ResourceExport::forQuery(User::query(), UserResource::class)
            ->paginatedJsonOrStreamedExport($this->exportRequest());

        self::assertInstanceOf(StreamedResponse::class, $response);

        $this->streamToString($response);

        Event::assertDispatched(ExportCompleted::class, static fn (ExportCompleted $event): bool => $event->actorId === null);
    }

    /**
     * Build a JSON-preferring request.
     *
     * @return \Illuminate\Http\Request
     */
    private function jsonRequest(): Request
    {
        return Request::create('/users', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
    }

    /**
     * Build a CSV-preferring export request.
     *
     * @return \Illuminate\Http\Request
     */
    private function exportRequest(): Request
    {
        return Request::create('/users', 'GET', server: ['HTTP_ACCEPT' => 'text/csv']);
    }

    /**
     * Build a CSV-preferring export request authenticated as the given actor.
     *
     * @param  int|string  $id
     * @return \Illuminate\Http\Request
     */
    private function exportRequestAs(int|string $id): Request
    {
        $request = $this->exportRequest();

        $request->setUserResolver(static fn (): GenericUser => new GenericUser(['id' => $id]));

        return $request;
    }

    /**
     * Split a CSV body into its data lines, dropping the heading and trailer.
     *
     * @param  string  $csv
     * @return list<string>
     */
    private function dataLines(string $csv): array
    {
        $lines = array_values(array_filter(explode("\n", $csv), static fn (string $line): bool => $line !== ''));

        array_shift($lines);

        return $lines;
    }
}
