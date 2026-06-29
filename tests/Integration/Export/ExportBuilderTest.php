<?php

declare(strict_types = 1);

namespace Tests\Integration\Export;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\Exporter\Events\StreamExportFailed;
use SineMacula\Exporter\Exceptions\InvalidExportSchema;
use SineMacula\Exporter\Exceptions\NoTabularRepresentation;
use SineMacula\Exporter\ExportBuilder;
use SineMacula\Exporter\Facades\Exporter;
use SineMacula\Exporter\Http\ExportFormat;
use SineMacula\Exporter\Http\MediaTypeRegistry;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\Enums\Strictness;
use SineMacula\Exporter\Writers\Truncation;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\ExporterTestCase;
use Tests\Support\Models\User;
use Tests\Support\Resources\PlainUserResource;
use Tests\Support\Resources\UserResource;
use Tests\Support\Schema\ActorAwareSchema;
use Tests\Support\Schema\ExplodingExportSchema;
use Tests\Support\Schema\FlexibleSchema;
use Tests\Support\Schema\UserExportSchema;

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
        self::assertSame(200, $response->getStatusCode());
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
     * It threads the toResponse() request into the schema.
     *
     * @return void
     */
    public function testToResponseUsesTheProvidedRequest(): void
    {
        $this->seedUsers(1);

        $request = Request::create('/');
        $request->setUserResolver(static fn (): Authenticatable => self::actor(42));

        $response = Exporter::collection($this->collection())
            ->schema(ActorAwareSchema::class)
            ->format('csv')
            ->toResponse($request);

        self::assertSame("ID,Actor\n1,42\n", $this->streamToString($response));
    }

    /**
     * It rejects non-key ordered query downloads before building a response.
     *
     * @return void
     */
    public function testDownloadRejectsNonKeyOrderedQueryBeforeStreaming(): void
    {
        $this->seedUsers(5);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('score');

        Exporter::query(User::query()->orderBy('score', 'desc'), UserResource::class) // @phpstan-ignore staticMethod.dynamicCall
            ->format('csv')
            ->download();
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
     * It removes the staging file when a mid-stream failure aborts store().
     *
     * @return void
     */
    public function testStoreCleansUpTheStagingFileWhenWritingFails(): void
    {
        $this->seedUsers(3);

        $disk = Storage::fake('exports');

        $before = $this->storeStagingFiles();

        try {
            Exporter::query(User::query(), UserResource::class) // @phpstan-ignore staticMethod.dynamicCall
                ->schema(ExplodingExportSchema::class)
                ->format('csv')
                ->store('exports', 'reports/boom.csv');

            self::fail('Expected the mid-stream failure to be re-thrown.');
        } catch (\RuntimeException) {
            // Mid-stream failure: store() must still clean up the staging file.
        }

        self::assertEmpty(array_diff($this->storeStagingFiles(), $before));

        $disk->assertMissing('reports/boom.csv');
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
     * It throws a 406 when a tabular format is registered without a writer.
     *
     * @return void
     */
    public function testThrowsWhenATabularFormatHasNoWriter(): void
    {
        $this->seedUsers(1);

        $registry = (new MediaTypeRegistry)
            ->register(new ExportFormat('weird', 'weird', 'application/x-weird', ['application/x-weird'], true));

        $builder = new ExportBuilder($this->collection(), null, $registry);

        $this->expectException(NoTabularRepresentation::class);

        $builder->format('weird')->toString();
    }

    /**
     * It throws a 406 when a hierarchical format has no writer.
     *
     * @return void
     */
    public function testThrowsWhenAHierarchicalFormatHasNoWriter(): void
    {
        $this->seedUsers(1);

        $registry = (new MediaTypeRegistry)
            ->register(new ExportFormat('plain', 'plain', 'application/x-plain', ['application/x-plain'], false));

        $builder = new ExportBuilder($this->collection(), null, $registry);

        $this->expectException(NoTabularRepresentation::class);

        $builder->format('plain')->toString();
    }

    /**
     * It negotiates the configured default format when none is set explicitly.
     *
     * @return void
     */
    public function testFormatDefaultsToTheConfiguredFormat(): void
    {
        $this->seedUsers(1);

        Config::set('exporter.default', 'tsv');

        $response = Exporter::collection($this->collection())->download();

        self::assertSame('text/tab-separated-values; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename=users.tsv', $response->headers->get('Content-Disposition'));
    }

    /**
     * It falls back to csv when the configured default is not a string.
     *
     * @return void
     */
    public function testFormatFallsBackToCsvForANonStringDefault(): void
    {
        $this->seedUsers(1);

        Config::set('exporter.default', ['not', 'a', 'string']);

        $response = Exporter::collection($this->collection())->download();

        self::assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
    }

    /**
     * It returns a 200 status from the real streamed download response.
     *
     * @return void
     */
    public function testStreamedDownloadResponseStatusIsOk(): void
    {
        $this->seedUsers(1);

        $response = Exporter::collection($this->collection())->format('csv')->download();

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * It drives the export from an explicit schema instance over the resource.
     *
     * The instance carries a distinct filename and a single column, so the
     * download name and body prove the instance is used rather than the
     * resource's own tabular schema.
     *
     * @return void
     */
    public function testSchemaInstanceOverridesTheResourceSchema(): void
    {
        $this->seedUsers(2);

        $schema = new FlexibleSchema(Request::create('/'), [Column::make('name', 'Name')], filename: 'invoice');

        $response = Exporter::collection($this->collection())->schema($schema)->format('csv')->download();

        self::assertSame('attachment; filename=invoice.csv', $response->headers->get('Content-Disposition'));

        $body = $this->streamToString($response);

        self::assertStringStartsWith("Name\n", $body);
        self::assertStringNotContainsString('Email', $body, 'The single-column instance schema must replace the resource schema.');
    }

    /**
     * It exports a single resource item hierarchically through its own class.
     *
     * @return void
     */
    public function testSingleResourceItemExportsHierarchically(): void
    {
        $this->seedUsers(1);

        $user = User::query()->firstOrFail();

        $json = Exporter::export(new UserResource($user))->format('json')->toString();

        $decoded = json_decode($json, true);

        self::assertIsArray($decoded);
        self::assertCount(1, $decoded);
        self::assertSame('User 1', $decoded[0]['name']);
    }

    /**
     * It counts the rows of a hierarchical stream export.
     *
     * @return void
     */
    public function testToStreamHierarchicalCountsRows(): void
    {
        $this->seedUsers(3);

        $stream = fopen('php://temp', 'r+b');

        self::assertIsResource($stream);

        $rows = Exporter::collection($this->collection())->format('json')->toStream($stream);

        rewind($stream);
        $decoded = json_decode((string) stream_get_contents($stream), true);
        fclose($stream);

        self::assertSame(3, $rows);
        self::assertIsArray($decoded);
        self::assertCount(3, $decoded);
    }

    /**
     * It throws a 406 naming the resource when its class is not tabular.
     *
     * @return void
     */
    public function testThrowsNamingTheResourceWhenTheClassIsNotTabular(): void
    {
        $this->expectException(NoTabularRepresentation::class);
        $this->expectExceptionMessage(PlainUserResource::class);

        Exporter::query(User::query(), PlainUserResource::class) // @phpstan-ignore staticMethod.dynamicCall
            ->format('csv')
            ->toString();
    }

    /**
     * It surfaces a mid-stream failure on the explicit download path through a
     * StreamExportFailed event and a logged truncation warning, mirroring the
     * negotiated path, rather than letting the throw escape the stream.
     *
     * @return void
     */
    public function testDownloadStreamFailureDispatchesStreamExportFailedAndLogs(): void
    {
        $this->seedUsers(2);

        Event::fake([StreamExportFailed::class]);
        Log::spy();

        $response = Exporter::collection($this->collection())
            ->schema(ExplodingExportSchema::class)
            ->format('csv')
            ->download();

        $this->streamToString($response);

        Event::assertDispatched(
            StreamExportFailed::class,
            static fn (StreamExportFailed $event): bool => $event->format === 'csv'
                && $event->rowsWritten                                    === 0
                && $event->exception->getMessage()                        === 'exploding column',
        );

        Log::shouldHaveReceived('warning') // @phpstan-ignore staticMethod.notFound
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => $message === 'Resource export stream truncated after the response had begun.'
                && $context['format']                                                === 'csv'
                && $context['rows_written']                                          === 0
                && $context['exception'] instanceof \Throwable);
    }

    /**
     * Actor-identifier cases: the raw identity and the value recorded for it.
     *
     * @return iterable<string, array{float|int|string|null, int|string|null}>
     */
    public static function actorIdentifierCases(): iterable
    {
        yield 'an integer identifier is preserved' => [5, 5];
        yield 'a string identifier is preserved' => ['user-9', 'user-9'];
        yield 'a float identifier is dropped' => [3.5, null];
        yield 'a null identifier is dropped' => [null, null];
    }

    /**
     * It records the request actor identifier on explicit stream failures.
     *
     * @param  float|int|string|null  $identifier
     * @param  int|string|null  $expected
     * @return void
     */
    #[DataProvider('actorIdentifierCases')]
    public function testDownloadStreamFailureRecordsTheRequestActor(float|int|string|null $identifier, int|string|null $expected): void
    {
        $this->seedUsers(1);

        Event::fake([StreamExportFailed::class]);

        $request = Request::create('/');
        $request->setUserResolver(static fn (): Authenticatable => self::actor($identifier));

        $response = Exporter::collection($this->collection())
            ->schema(ExplodingExportSchema::class)
            ->format('csv')
            ->request($request)
            ->download();

        $this->streamToString($response);

        Event::assertDispatched(
            StreamExportFailed::class,
            static fn (StreamExportFailed $event): bool => $event->actorId === $expected,
        );
    }

    /**
     * It reports how many data rows were written before a later stream failure.
     *
     * @return void
     */
    public function testDownloadStreamFailureReportsRowsAlreadyWritten(): void
    {
        $this->seedUsers(2);

        Event::fake([StreamExportFailed::class]);

        $request = Request::create('/');
        $schema  = new FlexibleSchema($request, [
            Column::make('id', 'ID'),
            Column::make('boom', 'Boom')->resolveUsing(static function (mixed $item): string {
                if (data_get($item, 'id') === 2) {
                    throw new \RuntimeException('second row failed');
                }

                return 'ok';
            }),
        ]);

        $response = Exporter::collection($this->collection())
            ->schema($schema)
            ->format('csv')
            ->download();

        self::assertSame("ID,Boom\n1,ok\n" . Truncation::CSV_FIELD . "\n", $this->streamToString($response));

        Event::assertDispatched(
            StreamExportFailed::class,
            static fn (StreamExportFailed $event): bool => $event->rowsWritten === 1
                && $event->exception->getMessage()                             === 'second row failed',
        );
    }

    /**
     * It logs the warnings a lenient export collects once it completes cleanly,
     * so a silently degraded column is visible to an operator.
     *
     * @return void
     */
    public function testLenientExportLogsCollectedWarnings(): void
    {
        $this->seedUsers(1);

        Log::spy();

        $schema = new FlexibleSchema(Request::create('/'), [
            Column::make('id', 'ID'),
            Column::make('broken', 'Broken')->cast('does-not-exist'),
        ], strictness: Strictness::LENIENT);

        Exporter::collection($this->collection())->schema($schema)->format('csv')->toString();

        Log::shouldHaveReceived('warning') // @phpstan-ignore staticMethod.notFound
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => $message === 'Resource export completed with warnings.'
                && $context['format']                                                === 'csv'
                && is_array($context['warnings'])
                && $context['warnings'] !== []);
    }

    /**
     * The formats that both honour an explicit eager-load hint.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function eagerLoadFormats(): iterable
    {
        yield 'tabular csv' => ['csv'];
        yield 'hierarchical json' => ['json'];
    }

    /**
     * It eager-loads the relations declared with with() for a query export, so
     * a nested resource does not N+1 across the streamed set.
     *
     * @param  string  $format
     * @return void
     */
    #[DataProvider('eagerLoadFormats')]
    public function testQueryExportEagerLoadsRelationsDeclaredWithWith(string $format): void
    {
        $this->seedUsers(3);
        $this->seedOrders(1, [10, 20]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        Exporter::query(User::query()->orderBy('id'), UserResource::class) // @phpstan-ignore staticMethod.dynamicCall
            ->format($format)
            ->with('orders')
            ->toString();

        $ordersQueries = 0;

        foreach (DB::getQueryLog() as $entry) {
            $sql = is_string($entry['query'] ?? null) ? $entry['query'] : '';

            if (!str_contains($sql, 'from "orders"')) {
                continue;
            }

            $ordersQueries++;
        }

        DB::disableQueryLog();

        self::assertSame(1, $ordersQueries, "The {$format} export must eager-load the declared relation in a single query.");
    }

    /**
     * It fails fast on an invalid preflight schema before the response begins.
     *
     * @return void
     */
    public function testDownloadFailsFastOnAnInvalidPreflightSchema(): void
    {
        $this->seedUsers(1);

        $schema = new FlexibleSchema(Request::create('/'), [
            Column::make('id', 'ID'),
            Column::make('broken', 'Broken')->cast('does-not-exist'),
        ]);

        $this->expectException(InvalidExportSchema::class);

        Exporter::collection($this->collection())
            ->schema($schema)
            ->format('csv')
            ->download();
    }

    /**
     * List the explicit-export staging temp files currently on disk.
     *
     * Scoped to store()'s dedicated prefix, which only ExportBuilder::stage()
     * allocates, so a parallel test's temp files cannot contaminate the
     * assertion.
     *
     * @return list<string>
     */
    private function storeStagingFiles(): array
    {
        $files = glob(sys_get_temp_dir() . '/export_store_*');

        return $files === false ? [] : $files;
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

    /**
     * Build an authenticatable whose identifier is the given raw value.
     *
     * @param  float|int|string|null  $identifier
     * @return \Illuminate\Contracts\Auth\Authenticatable
     */
    private static function actor(float|int|string|null $identifier): Authenticatable
    {
        return new class ($identifier) implements Authenticatable {
            /**
             * Create the stub actor.
             *
             * @param  float|int|string|null  $identifier
             */
            public function __construct(

                /** The raw identifier the resolver returns. */
                private readonly float|int|string|null $identifier,
            ) {}

            /**
             * Get the name of the unique identifier for the user.
             *
             * @return string
             */
            #[\Override]
            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            /**
             * Get the unique identifier for the user.
             *
             * @return float|int|string|null
             */
            #[\Override]
            public function getAuthIdentifier(): float|int|string|null
            {
                return $this->identifier;
            }

            /**
             * Get the name of the password attribute for the user.
             *
             * @return string
             */
            #[\Override]
            public function getAuthPasswordName(): string
            {
                return 'password';
            }

            /**
             * Get the password for the user.
             *
             * @return string
             */
            #[\Override]
            public function getAuthPassword(): string
            {
                return '';
            }

            /**
             * Get the token value for the "remember me" session.
             *
             * @return string
             */
            #[\Override]
            public function getRememberToken(): string
            {
                return '';
            }

            /**
             * Set the token value for the "remember me" session.
             *
             * @param  mixed  $value
             * @return void
             */
            #[\Override]
            public function setRememberToken(mixed $value): void {}

            /**
             * Get the column name for the "remember me" token.
             *
             * @return string
             */
            #[\Override]
            public function getRememberTokenName(): string
            {
                return 'remember_token';
            }
        };
    }
}
