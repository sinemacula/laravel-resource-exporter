<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\Exporter\Engine;
use SineMacula\Exporter\Http\Concerns\RespondsWithExports;
use SineMacula\Exporter\Http\ExportFormat;
use SineMacula\Exporter\Http\ExportNegotiator;
use SineMacula\Exporter\Http\ExportResourceCollection;
use SineMacula\Exporter\Http\MediaTypeRegistry;
use SineMacula\Exporter\Sinks\StreamedResponseSink;
use SineMacula\Exporter\Sources\ResourceCollectionSource;
use SineMacula\Exporter\Sources\ResourceItemSource;
use SineMacula\Exporter\Writers\CsvWriter;
use Tests\Support\V3\ExporterTestCase;
use Tests\Support\V3\Models\User;
use Tests\Support\V3\Resources\PlainUserResource;
use Tests\Support\V3\Resources\UserResource;

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
}
