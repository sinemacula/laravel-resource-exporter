<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Exceptions\NoTabularRepresentation;
use SineMacula\Exporter\Export\ExportSpecification;
use SineMacula\Exporter\ExportBuilder;
use SineMacula\Exporter\Facades\Exporter;
use SineMacula\Exporter\Jobs\ExportToDiskJob;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\V3\ExporterTestCase;
use Tests\Support\V3\Models\User;
use Tests\Support\V3\Resources\UserResource;
use Tests\Support\V3\Schema\UserExportSchema;

/**
 * Integration tests for the fluent explicit-export builder verbs.
 *
 * Exercises each terminal verb against a real testbench application: download()
 * and toResponse() build streamed attachment responses, store() writes to a
 * fake disk, toString() and toStream() buffer the bytes, and queue() hands a
 * full-set export to the queued-to-disk pipeline.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExportBuilder::class)]
final class ExportBuilderTest extends ExporterTestCase
{
    /** @var string The byte-stable CSV body two seeded users export to */
    private const string CSV_BODY = "ID,Name,Email,Active,Joined\n"
        . "1,\"User 1\",user1@example.test,Yes,2026-01-01\n"
        . "2,\"User 2\",user2@example.test,Yes,2026-01-01\n";

    /**
     * It builds a streamed attachment download with the right type and body.
     *
     * @return void
     */
    public function testDownloadReturnsAStreamedAttachmentResponse(): void
    {
        $this->seedUsers(2);

        $response = Exporter::collection($this->collection())->format('csv')->download('members');

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename=members.csv', $response->headers->get('Content-Disposition'));
        self::assertSame('Accept', $response->headers->get('Vary'));
        self::assertSame(self::CSV_BODY, $this->streamToString($response));
    }

    /**
     * It falls back to the schema filename hint when none is given to download.
     *
     * @return void
     */
    public function testDownloadFallsBackToTheSchemaFilename(): void
    {
        $this->seedUsers(1);

        $response = Exporter::collection($this->collection())->download();

        self::assertSame('attachment; filename=users.csv', $response->headers->get('Content-Disposition'));
    }

    /**
     * It builds a streamed export response honouring the explicit format.
     *
     * @return void
     */
    public function testToResponseStreamsTheExplicitFormat(): void
    {
        $this->seedUsers(1);

        $response = Exporter::collection($this->collection())->format('tsv')->toResponse(Request::create('/'));

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame('text/tab-separated-values; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename=users.tsv', $response->headers->get('Content-Disposition'));
        self::assertStringContainsString("ID\tName\tEmail\tActive\tJoined", $this->streamToString($response));
    }

    /**
     * It writes the export to a storage disk and returns the destination path.
     *
     * @return void
     */
    public function testStoreWritesToTheDiskAndReturnsThePath(): void
    {
        $this->seedUsers(2);

        $disk = Storage::fake('exports');

        $path = Exporter::collection($this->collection())->format('csv')->store('exports', 'reports/users.csv');

        self::assertSame('reports/users.csv', $path);

        $disk->assertExists('reports/users.csv');

        self::assertSame(self::CSV_BODY, $disk->get('reports/users.csv'));
    }

    /**
     * It buffers the export to a string.
     *
     * @return void
     */
    public function testToStringBuffersTheBytes(): void
    {
        $this->seedUsers(2);

        $csv = Exporter::collection($this->collection())->format('csv')->toString();

        self::assertSame(self::CSV_BODY, $csv);
    }

    /**
     * It streams the export into a writable stream and returns the row count.
     *
     * @return void
     */
    public function testToStreamWritesIntoTheStreamAndCountsRows(): void
    {
        $this->seedUsers(2);

        $stream = fopen('php://temp', 'r+b');

        self::assertIsResource($stream);

        $rows = Exporter::collection($this->collection())->format('csv')->toStream($stream);

        rewind($stream);
        $contents = (string) stream_get_contents($stream);
        fclose($stream);

        self::assertSame(2, $rows);
        self::assertSame(self::CSV_BODY, $contents);
    }

    /**
     * It hands a full query subject to the queued-to-disk pipeline.
     *
     * @return void
     */
    public function testQueueDispatchesTheExportToDiskJob(): void
    {
        Bus::fake();

        $dispatch = Exporter::query(User::query(), UserResource::class) // @phpstan-ignore staticMethod.dynamicCall
            ->format('csv')
            ->schema(UserExportSchema::class)
            ->as('members')
            ->queue('exports', 'exports/users.csv');

        self::assertInstanceOf(PendingDispatch::class, $dispatch);

        // The pending dispatch is flushed on destruction; release it so the job
        // reaches the bus fake before the assertion runs.
        unset($dispatch);

        Bus::assertDispatched(ExportToDiskJob::class);
    }

    /**
     * It forwards the builder state onto the queued export specification.
     *
     * @return void
     */
    public function testQueueForwardsTheBuilderStateToTheSpecification(): void
    {
        $fake = Exporter::fake();

        Exporter::query(User::query(), UserResource::class) // @phpstan-ignore staticMethod.dynamicCall
            ->format('csv')
            ->schema(UserExportSchema::class)
            ->as('members')
            ->queue('exports', 'exports/users.csv');

        $fake->assertQueued(static fn (ExportSpecification $spec): bool => $spec->model === User::class
            && $spec->resource                                                          === UserResource::class
            && $spec->schema                                                            === UserExportSchema::class
            && $spec->format                                                            === 'csv'
            && $spec->disk                                                              === 'exports'
            && $spec->path                                                              === 'exports/users.csv'
            && $spec->filename                                                          === 'members');
    }

    /**
     * It streams a hierarchical format and honours the chunk size.
     *
     * @return void
     */
    public function testChunkAndHierarchicalJsonToString(): void
    {
        $this->seedUsers(2);

        $json = Exporter::collection($this->collection())->format('json')->chunk(1)->toString();

        $decoded = json_decode($json, true);

        self::assertIsArray($decoded);
        self::assertCount(2, $decoded);
        self::assertSame('User 1', $decoded[0]['name']);
    }

    /**
     * It exports through an explicit schema instance.
     *
     * @return void
     */
    public function testSchemaInstanceDrivesTheExport(): void
    {
        $this->seedUsers(2);

        $csv = Exporter::collection($this->collection())
            ->schema(new UserExportSchema(Request::create('/')))
            ->format('csv')
            ->toString();

        self::assertSame(self::CSV_BODY, $csv);
    }

    /**
     * It falls back to the generic export filename for a hierarchical download.
     *
     * @return void
     */
    public function testHierarchicalDownloadFilenameFallsBackToExport(): void
    {
        $this->seedUsers(1);

        $response = Exporter::collection($this->collection())->format('json')->download();

        self::assertSame('attachment; filename=export.json', $response->headers->get('Content-Disposition'));
    }

    /**
     * It records a faked toStream verb without writing the stream.
     *
     * @return void
     */
    public function testToStreamUnderFakeIsRecorded(): void
    {
        $this->seedUsers(3);

        $fake   = Exporter::fake();
        $stream = fopen('php://temp', 'r+b');

        self::assertIsResource($stream);

        $rows = Exporter::collection($this->collection())->format('csv')->toStream($stream);

        rewind($stream);

        self::assertSame(3, $rows);
        self::assertSame('', (string) stream_get_contents($stream), 'A faked stream verb writes no bytes.');

        fclose($stream);

        $fake->assertExportedRows(3);
    }

    /**
     * It resolves the schema from the resource when none is set explicitly.
     *
     * @return void
     */
    public function testResolvesTheSchemaFromTheResourceClass(): void
    {
        $this->seedUsers(2);

        $csv = Exporter::query(User::query()->orderBy('id'), UserResource::class) // @phpstan-ignore staticMethod.dynamicCall
            ->format('csv')
            ->toString();

        self::assertSame(self::CSV_BODY, $csv);
    }

    /**
     * It throws a 406 when no schema and no tabular-capable resource are known.
     *
     * @return void
     */
    public function testThrowsWhenNoTabularRepresentationIsAvailable(): void
    {
        $this->expectException(NoTabularRepresentation::class);

        Exporter::export(User::query())->format('csv')->toString();
    }

    /**
     * It honours an explicit filename hint set with as() on a download.
     *
     * @return void
     */
    public function testDownloadUsesTheExplicitFilenameHint(): void
    {
        $this->seedUsers(1);

        $response = Exporter::collection($this->collection())->format('csv')->as('custom')->download();

        self::assertSame('attachment; filename=custom.csv', $response->headers->get('Content-Disposition'));
    }

    /**
     * It refuses to queue when handed a schema instance instead of a class.
     *
     * @return void
     */
    public function testQueueRejectsASchemaInstance(): void
    {
        $this->expectException(\LogicException::class);

        Exporter::query(User::query(), UserResource::class) // @phpstan-ignore staticMethod.dynamicCall
            ->schema(new UserExportSchema(Request::create('/')))
            ->queue('exports', 'exports/users.csv');
    }

    /**
     * It refuses to queue a query subject with no resource class.
     *
     * @return void
     */
    public function testQueueRejectsAQueryWithoutAResource(): void
    {
        $this->expectException(\LogicException::class);

        Exporter::export(User::query())->queue('exports', 'exports/users.csv');
    }

    /**
     * It refuses to queue a non-query subject.
     *
     * @return void
     */
    public function testQueueRejectsANonQuerySubject(): void
    {
        $this->seedUsers(1);

        $this->expectException(\LogicException::class);

        Exporter::collection($this->collection())->queue('exports', 'exports/users.csv');
    }

    /**
     * It refuses to queue a query that already carries constraints.
     *
     * @return void
     */
    public function testQueueRejectsAConstrainedQuery(): void
    {
        $this->expectException(\LogicException::class);

        Exporter::query(User::query()->where('active', true), UserResource::class) // @phpstan-ignore staticMethod.dynamicCall
            ->queue('exports', 'exports/users.csv');
    }

    /**
     * Build an export-aware collection of every seeded user.
     *
     * @return \Illuminate\Http\Resources\Json\ResourceCollection
     */
    private function collection(): ResourceCollection
    {
        return UserResource::collection(User::query()->orderBy('id')->get()); // @phpstan-ignore staticMethod.dynamicCall
    }
}
