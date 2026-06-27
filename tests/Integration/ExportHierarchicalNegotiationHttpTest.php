<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Http\Concerns\RespondsWithExports;
use SineMacula\Exporter\Http\ExportFormat;
use SineMacula\Exporter\Http\ExportNegotiator;
use SineMacula\Exporter\Http\ExportResourceCollection;
use SineMacula\Exporter\Http\MediaTypeRegistry;
use SineMacula\Exporter\Sinks\StreamedResponseSink;
use SineMacula\Exporter\Sources\ResourceCollectionSource;
use SineMacula\Exporter\Sources\ResourceItemSource;
use SineMacula\Exporter\Writers\JsonWriter;
use SineMacula\Exporter\Writers\NdjsonWriter;
use SineMacula\Exporter\Writers\XmlWriter;
use Tests\Support\V3\ExporterTestCase;
use Tests\Support\V3\Models\User;
use Tests\Support\V3\Resources\PlainUserResource;
use Tests\Support\V3\Resources\UserResource;

/**
 * HTTP negotiation tests for the hierarchical XML, JSON and NDJSON formats.
 *
 * Proves that the hierarchical formats serialise each item's own toArray shape
 * (not the tabular Column schema), so a resource without a tabular schema is
 * still exportable as XML/JSON/NDJSON yet correctly 406s for a tabular format.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExportNegotiator::class)]
#[CoversClass(MediaTypeRegistry::class)]
#[CoversClass(ExportFormat::class)]
#[CoversClass(RespondsWithExports::class)]
#[CoversClass(ExportResourceCollection::class)]
#[CoversClass(XmlWriter::class)]
#[CoversClass(JsonWriter::class)]
#[CoversClass(NdjsonWriter::class)]
#[CoversClass(StreamedResponseSink::class)]
#[CoversClass(ResourceItemSource::class)]
#[CoversClass(ResourceCollectionSource::class)]
final class ExportHierarchicalNegotiationHttpTest extends ExporterTestCase
{
    /**
     * It streams a collection as XML for the application/xml Accept header.
     *
     * @return void
     */
    public function testCollectionNegotiatesXmlByApplicationXml(): void
    {
        $this->seedUsers(2);

        $response = $this->get('/plain', ['Accept' => 'application/xml']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename=export.xml');
        $response->assertHeader('Vary', 'Accept');

        self::assertSame(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . '<data>'
            . '<item><id>1</id><name>User 1</name></item>'
            . '<item><id>2</id><name>User 2</name></item>'
            . "</data>\n",
            $response->streamedContent(),
        );
    }

    /**
     * It also negotiates XML for the text/xml Accept header.
     *
     * @return void
     */
    public function testCollectionNegotiatesXmlByTextXml(): void
    {
        $this->seedUsers(1);

        $response = $this->get('/plain', ['Accept' => 'text/xml']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        self::assertStringContainsString('<item><id>1</id><name>User 1</name></item>', $response->streamedContent());
    }

    /**
     * It streams a collection as NDJSON, one item per line.
     *
     * @return void
     */
    public function testCollectionNegotiatesNdjson(): void
    {
        $this->seedUsers(2);

        $response = $this->get('/plain', ['Accept' => 'application/x-ndjson']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/x-ndjson; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename=export.ndjson');
        $response->assertHeader('Vary', 'Accept');

        self::assertSame(
            '{"id":1,"name":"User 1"}' . "\n"
            . '{"id":2,"name":"User 2"}' . "\n",
            $response->streamedContent(),
        );
    }

    /**
     * It streams a collection as a JSON array for the explicit format
     * parameter.
     *
     * @return void
     */
    public function testCollectionNegotiatesJsonByFormatParameter(): void
    {
        $this->seedUsers(2);

        $response = $this->get('/plain?format=json');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename=export.json');
        $response->assertHeader('Vary', 'Accept');

        self::assertSame(
            '[{"id":1,"name":"User 1"},{"id":2,"name":"User 2"}]',
            $response->streamedContent(),
        );
    }

    /**
     * It defers to the framework's native JSON response (with Vary: Accept) for
     * an application/json Accept header, rather than streaming.
     *
     * @return void
     */
    public function testCollectionDefersToNativeJsonForApplicationJson(): void
    {
        $this->seedUsers(2);

        $response = $this->get('/plain', ['Accept' => 'application/json']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertHeader('Vary', 'Accept');
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.name', 'User 1');
    }

    /**
     * It exports a non-tabular resource as XML, JSON and NDJSON, but 406s
     * for a tabular format the resource cannot describe.
     *
     * @return void
     */
    public function testNonTabularResourceExportsHierarchicallyButRejectsCsv(): void
    {
        $this->seedUsers(1);

        $this->get('/plain', ['Accept' => 'application/xml'])->assertOk();
        $this->get('/plain', ['Accept' => 'application/x-ndjson'])->assertOk();
        $this->get('/plain?format=json')->assertOk();

        $this->get('/plain', ['Accept' => 'text/csv'])->assertStatus(406);
        $this->get('/plain', ['Accept' => 'text/tab-separated-values'])->assertStatus(406);
    }

    /**
     * It negotiates a single top-level item as XML, streaming its toArray
     * shape.
     *
     * @return void
     */
    public function testSingleItemNegotiatesXml(): void
    {
        $this->seedUsers(1);

        $response = $this->get('/plain-item', ['Accept' => 'application/xml']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        self::assertSame(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<data><item><id>1</id><name>User 1</name></item></data>\n",
            $response->streamedContent(),
        );
    }

    /**
     * It serialises a tabular resource's hierarchical toArray shape (not its
     * Column schema) when a hierarchical format is negotiated.
     *
     * @return void
     */
    public function testTabularResourceSerialisesToArrayShapeForXml(): void
    {
        $this->seedUsers(1);

        $response = $this->get('/users', ['Accept' => 'application/xml']);
        $body     = $response->streamedContent();

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        self::assertStringContainsString('<email>user1@example.test</email>', $body);
        self::assertStringContainsString('<active>true</active>', $body);
        self::assertStringNotContainsString('Joined', $body);
    }

    /**
     * Register the routes the hierarchical negotiation scenarios hit.
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
        $router->get('/plain', static fn (): mixed => PlainUserResource::collection(User::query()->orderBy('id')->get())); // @phpstan-ignore staticMethod.dynamicCall
        $router->get('/plain-item', static fn (): mixed => new PlainUserResource(User::query()->orderBy('id')->firstOrFail())); // @phpstan-ignore staticMethod.dynamicCall
    }
}
