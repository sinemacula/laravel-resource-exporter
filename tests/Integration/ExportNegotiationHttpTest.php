<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\Exporter\Engine;
use SineMacula\Exporter\Events\StreamExportFailed;
use SineMacula\Exporter\Http\Concerns\RespondsWithExports;
use SineMacula\Exporter\Http\ExportFormat;
use SineMacula\Exporter\Http\ExportNegotiator;
use SineMacula\Exporter\Http\ExportResourceCollection;
use SineMacula\Exporter\Http\MediaTypeRegistry;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\Enums\Strictness;
use SineMacula\Exporter\Schema\TabularSchema;
use SineMacula\Exporter\Sinks\StreamedResponseSink;
use SineMacula\Exporter\Sources\ResourceCollectionSource;
use SineMacula\Exporter\Sources\ResourceItemSource;
use SineMacula\Exporter\Writers\CsvWriter;
use Tests\Support\V3\ArraySource;
use Tests\Support\V3\ExporterTestCase;
use Tests\Support\V3\Models\User;
use Tests\Support\V3\Resources\PlainUserResource;
use Tests\Support\V3\Resources\UserResource;
use Tests\Support\V3\Schema\FlexibleSchema;

/**
 * HTTP negotiation tests covering item, collection, JSON fallback, and 406.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExportNegotiator::class)]
#[CoversClass(MediaTypeRegistry::class)]
#[CoversClass(ExportFormat::class)]
#[CoversClass(ExportResourceCollection::class)]
#[CoversClass(Engine::class)]
#[CoversClass(CsvWriter::class)]
#[CoversClass(StreamedResponseSink::class)]
#[CoversClass(ResourceItemSource::class)]
#[CoversClass(ResourceCollectionSource::class)]
#[CoversTrait(RespondsWithExports::class)]
final class ExportNegotiationHttpTest extends ExporterTestCase
{
    /**
     * It streams a collection as CSV for the Accept header.
     *
     * @return void
     */
    public function testCollectionNegotiatesCsvByAcceptHeader(): void
    {
        $this->seedUsers(2);

        $response = $this->get('/users', ['Accept' => 'text/csv']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename=users.csv');
        $response->assertHeader('Vary', 'Accept');

        self::assertSame(
            "ID,Name,Email,Active,Joined\n"
            . "1,\"User 1\",user1@example.test,Yes,2026-01-01\n"
            . "2,\"User 2\",user2@example.test,Yes,2026-01-01\n",
            $response->streamedContent(),
        );
    }

    /**
     * It streams a collection as CSV for the explicit format parameter.
     *
     * @return void
     */
    public function testCollectionNegotiatesCsvByFormatParameter(): void
    {
        $this->seedUsers(1);

        $response = $this->get('/users?format=csv');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        self::assertStringContainsString('1,"User 1",user1@example.test,Yes,2026-01-01', $response->streamedContent());
    }

    /**
     * It returns JSON with Vary: Accept when no export format is negotiated.
     *
     * @return void
     */
    public function testCollectionReturnsJsonWithVaryWhenNotNegotiated(): void
    {
        $this->seedUsers(2);

        $response = $this->get('/users', ['Accept' => 'application/json']);

        $response->assertOk();
        $response->assertHeader('Vary', 'Accept');
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.email', 'user1@example.test');
    }

    /**
     * It negotiates a single top-level item as CSV.
     *
     * @return void
     */
    public function testSingleItemNegotiatesCsv(): void
    {
        $this->seedUsers(1);

        $response = $this->get('/user', ['Accept' => 'text/csv']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        self::assertSame(
            "ID,Name,Email,Active,Joined\n"
            . "1,\"User 1\",user1@example.test,Yes,2026-01-01\n",
            $response->streamedContent(),
        );
    }

    /**
     * It returns 406 for a collection whose item has no tabular schema.
     *
     * @return void
     */
    public function testCollectionWithoutSchemaReturns406(): void
    {
        $this->seedUsers(1);

        $this->get('/plain', ['Accept' => 'text/csv'])->assertStatus(406);
    }

    /**
     * It returns 406 for a single item with no tabular schema.
     *
     * @return void
     */
    public function testItemWithoutSchemaReturns406(): void
    {
        $this->seedUsers(1);

        $this->get('/plain-item', ['Accept' => 'text/csv'])->assertStatus(406);
    }

    /**
     * It does not negotiate a resource nested inside a JSON response.
     *
     * @return void
     */
    public function testNestedResourceDoesNotNegotiate(): void
    {
        $this->seedUsers(1);

        $response = $this->get('/nested', ['Accept' => 'text/csv']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertJsonPath('user.email', 'user1@example.test');
    }

    /**
     * The completion callback fires on success carrying the exact data-row
     * count the stream emitted.
     *
     * @return void
     */
    public function testStreamExportInvokesCompletionCallbackWithTheRowCount(): void
    {
        $request  = Request::create('/', 'GET', server: ['HTTP_ACCEPT' => 'text/csv']);
        $received = null;

        $response = (new ExportNegotiator)->streamExport(
            new ArraySource([['id' => 1], ['id' => 2], ['id' => 3]]),
            new FlexibleSchema($request, [Column::make('id', 'ID')]),
            'csv',
            $request,
            static function (int $rows) use (&$received): void {
                $received = $rows;
            },
        );

        $this->streamToString($response);

        self::assertSame(3, $received);
    }

    /**
     * The completion callback fires for an empty source with a row count of
     * exactly zero - the count starts at zero, not below it.
     *
     * @return void
     */
    public function testStreamExportReportsZeroRowsForAnEmptySource(): void
    {
        $request  = Request::create('/', 'GET', server: ['HTTP_ACCEPT' => 'text/csv']);
        $received = null;

        $response = (new ExportNegotiator)->streamExport(
            new ArraySource([]),
            new FlexibleSchema($request, [Column::make('id', 'ID')]),
            'csv',
            $request,
            static function (int $rows) use (&$received): void {
                $received = $rows;
            },
        );

        $this->streamToString($response);

        self::assertSame(0, $received);
    }

    /**
     * It logs lenient-mode warnings collected by the negotiated stream once the
     * stream completes cleanly.
     *
     * @return void
     */
    public function testStreamExportLogsCollectedWarnings(): void
    {
        Log::spy();

        $request = Request::create('/', 'GET', server: ['HTTP_ACCEPT' => 'text/csv']);
        $schema  = new FlexibleSchema($request, [
            Column::make('id', 'ID'),
            Column::make('broken', 'Broken')->cast('does-not-exist'),
        ], strictness: Strictness::LENIENT);

        $response = (new ExportNegotiator)->streamExport(
            new ArraySource([['id' => 1]]),
            $schema,
            'csv',
            $request,
        );

        self::assertSame("ID\n1\n", $this->streamToString($response));

        Log::shouldHaveReceived('warning') // @phpstan-ignore staticMethod.notFound
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => $message === 'Resource export completed with warnings.'
                && $context['format']                                                === 'csv'
                && is_array($context['warnings'])
                && $context['warnings'] !== []);
    }

    /**
     * A failure on the very first row skips the completion callback, fires the
     * failure event with zero rows written, and logs the truncation context.
     *
     * @return void
     */
    public function testStreamFailureSkipsCompletionAndLogsTruncationContext(): void
    {
        Event::fake([StreamExportFailed::class]);
        Log::spy();

        $request   = Request::create('/', 'GET', server: ['HTTP_ACCEPT' => 'text/csv']);
        $completed = false;

        $response = (new ExportNegotiator)->streamExport(
            new ArraySource([['id' => 1], ['id' => 2]]),
            $this->firstRowThrows($request),
            'csv',
            $request,
            static function () use (&$completed): void {
                $completed = true;
            },
        );

        $this->streamToString($response);

        self::assertFalse($completed, 'The completion callback must not run after a mid-stream failure.');

        Event::assertDispatched(
            StreamExportFailed::class,
            static fn (StreamExportFailed $event): bool => $event->format === 'csv' && $event->rowsWritten === 0,
        );

        Log::shouldHaveReceived('warning') // @phpstan-ignore staticMethod.notFound
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => $message === 'Resource export stream truncated after the response had begun.'
                && $context['format']                                                === 'csv'
                && $context['rows_written']                                          === 0
                && $context['exception'] instanceof \Throwable
                && $context['exception']->getMessage() === 'boom');
    }

    /**
     * Actor-identifier cases: the raw identity and the value the negotiator
     * records for it.
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
     * The resolved actor identifier carried by the failure event keeps an int
     * or string identity, but drops anything that is neither.
     *
     * @param  float|int|string|null  $identifier
     * @param  int|string|null  $expected
     * @return void
     */
    #[DataProvider('actorIdentifierCases')]
    public function testStreamFailureRecordsTheResolvedActorIdentifier(float|int|string|null $identifier, int|string|null $expected): void
    {
        Event::fake([StreamExportFailed::class]);

        $request = Request::create('/', 'GET', server: ['HTTP_ACCEPT' => 'text/csv']);
        $request->setUserResolver(static fn (): Authenticatable => self::actor($identifier));

        $response = (new ExportNegotiator)->streamExport(
            new ArraySource([['id' => 1]]),
            $this->firstRowThrows($request),
            'csv',
            $request,
        );

        $this->streamToString($response);

        Event::assertDispatched(
            StreamExportFailed::class,
            static fn (StreamExportFailed $event): bool => $event->actorId === $expected,
        );
    }

    /**
     * Register the routes the negotiation scenarios hit.
     *
     * @param  mixed  $router
     * @return void
     */
    #[\Override]
    protected function defineRoutes(mixed $router): void
    {
        if (!$router instanceof Router) {
            return;
        }

        $router->get('/users', static fn (): mixed => UserResource::collection(User::query()->orderBy('id')->get())); // @phpstan-ignore staticMethod.dynamicCall
        $router->get('/user', static fn (): mixed => new UserResource(User::query()->orderBy('id')->firstOrFail())); // @phpstan-ignore staticMethod.dynamicCall
        $router->get('/plain', static fn (): mixed => PlainUserResource::collection(User::query()->get()));
        $router->get('/plain-item', static fn (): mixed => new PlainUserResource(User::query()->firstOrFail()));
        $router->get('/nested', static fn (): mixed => response()->json(['user' => new UserResource(User::query()->firstOrFail())]));
    }

    /**
     * Build a tabular schema whose only column throws on the first data row.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \SineMacula\Exporter\Schema\TabularSchema
     */
    private function firstRowThrows(Request $request): TabularSchema
    {
        return new FlexibleSchema($request, [
            Column::make('id', 'ID')->resolveUsing(static function (array|object $item): mixed {
                if (data_get($item, 'id') === 1) {
                    throw new \RuntimeException('boom');
                }

                return data_get($item, 'id');
            }),
        ]);
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
