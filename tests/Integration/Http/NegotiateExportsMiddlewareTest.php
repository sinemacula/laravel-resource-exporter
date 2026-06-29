<?php

declare(strict_types = 1);

namespace Tests\Integration\Http;

use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\Exporter\Http\Middleware\NegotiateExports;
use SineMacula\Exporter\Schema\DecodedPayloadSchema;
use Tests\Support\ExporterTestCase;

/**
 * Integration tests for the legacy NegotiateExports middleware.
 *
 * The middleware is the documented opt-in legacy on-ramp: it converts an
 * already-rendered JSON response into a tabular export when one is negotiated,
 * but only on routes it is explicitly attached to. These tests prove it
 * negotiates when applied and, crucially, that the package never enables it
 * globally - a bare route is untouched even when an export format is requested.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(NegotiateExports::class)]
#[CoversClass(DecodedPayloadSchema::class)]
final class NegotiateExportsMiddlewareTest extends ExporterTestCase
{
    /**
     * It flattens a JSON response to CSV when explicitly applied to a route.
     *
     * @return void
     */
    public function testMiddlewareNegotiatesCsvWhenApplied(): void
    {
        $response = $this->get('/legacy', ['Accept' => 'text/csv']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Vary', 'Accept');

        self::assertSame(
            "Id,Name\n1,Ada\n2,Bob\n",
            $response->streamedContent(),
        );
    }

    /**
     * It defers to the JSON response, with Vary, when no export is negotiated.
     *
     * @return void
     */
    public function testMiddlewarePassesJsonThroughWithVary(): void
    {
        $response = $this->get('/legacy', ['Accept' => 'application/json']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertHeader('Vary', 'Accept');
        $response->assertExactJson([
            ['id' => 1, 'name' => 'Ada'],
            ['id' => 2, 'name' => 'Bob'],
        ]);
    }

    /**
     * It is not enabled globally - a bare route is never negotiated.
     *
     * @return void
     */
    public function testMiddlewareIsNotEnabledGlobally(): void
    {
        $response = $this->get('/bare', ['Accept' => 'text/csv']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertExactJson([
            ['id' => 1, 'name' => 'Ada'],
            ['id' => 2, 'name' => 'Bob'],
        ]);
    }

    /**
     * It unwraps the paginator data envelope and JSON-encodes nested values.
     *
     * @return void
     */
    public function testMiddlewareUnwrapsEnvelopeAndEncodesNestedValues(): void
    {
        $response = $this->get('/legacy-envelope', ['Accept' => 'text/csv']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        self::assertSame("Id,Tags\n1,\"[\"\"a\"\",\"\"b\"\"]\"\n", $response->streamedContent());
    }

    /**
     * It treats a single JSON object as a one-row export.
     *
     * @return void
     */
    public function testMiddlewareExportsASingleObjectAsOneRow(): void
    {
        $response = $this->get('/legacy-object', ['Accept' => 'text/csv']);

        $response->assertOk();

        self::assertSame("Id,Name\n9,Zed\n", $response->streamedContent());
    }

    /**
     * A list mixing object rows with scalars cannot be flattened, so the JSON
     * passes through untouched rather than the scalars being silently dropped.
     *
     * @return void
     */
    public function testMiddlewareLeavesAListMixingObjectsAndScalarsUntouched(): void
    {
        $response = $this->get('/legacy-mixed', ['Accept' => 'text/csv']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertHeader('Vary', 'Accept');
        $response->assertExactJson([
            ['id' => 1, 'name' => 'Ada'],
            5,
        ]);
    }

    /**
     * Integer payload keys are cast to string column names, so a numeric-keyed
     * object still flattens to a tabular export rather than erroring.
     *
     * @return void
     */
    public function testMiddlewareCastsIntegerKeysToStringColumns(): void
    {
        $response = $this->get('/legacy-int-keys', ['Accept' => 'text/csv']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        self::assertSame("10,11\n\"{\"\"v\"\":\"\"a\"\"}\",\"{\"\"v\"\":\"\"b\"\"}\"\n", $response->streamedContent());
    }

    /**
     * A nested value is JSON-encoded with slashes left unescaped, matching the
     * documented blind flatten.
     *
     * @return void
     */
    public function testMiddlewareEncodesNestedValuesWithUnescapedSlashes(): void
    {
        $response = $this->get('/legacy-nested', ['Accept' => 'text/csv']);

        $response->assertOk();

        self::assertSame("Id,Link\n1,\"{\"\"u\"\":\"\"a/b\"\"}\"\n", $response->streamedContent());
    }

    /**
     * The routes whose bodies the middleware cannot flatten to a table.
     *
     * @return iterable<string, array{string}>
     */
    public static function unflattenableRoutes(): iterable
    {
        yield 'non-json response' => ['/legacy-text'];
        yield 'scalar json body' => ['/legacy-scalar'];
        yield 'empty json body' => ['/legacy-empty'];
    }

    /**
     * It leaves an unflattenable JSON body untouched, with Vary, for export.
     *
     * @param  string  $route
     * @return void
     */
    #[DataProvider('unflattenableRoutes')]
    public function testMiddlewareLeavesUnflattenableBodiesUntouched(string $route): void
    {
        $response = $this->get($route, ['Accept' => 'text/csv']);

        $response->assertOk();
        $response->assertHeader('Vary', 'Accept');
        self::assertStringNotContainsString('text/csv', (string) $response->headers->get('Content-Type'));
    }

    /**
     * Register the routes the middleware scenarios hit.
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

        $payload = [
            ['id' => 1, 'name' => 'Ada'],
            ['id' => 2, 'name' => 'Bob'],
        ];

        $router->get('/legacy', static fn (): mixed => response()->json($payload))->middleware('exporter.negotiate');
        $router->get('/bare', static fn (): mixed => response()->json($payload));

        $router->get('/legacy-envelope', static fn (): mixed => response()->json(['data' => [['id' => 1, 'tags' => ['a', 'b']]]]))->middleware('exporter.negotiate');
        $router->get('/legacy-object', static fn (): mixed => response()->json(['id' => 9, 'name' => 'Zed']))->middleware('exporter.negotiate');
        $router->get('/legacy-mixed', static fn (): mixed => response()->json([['id' => 1, 'name' => 'Ada'], 5]))->middleware('exporter.negotiate');
        $router->get('/legacy-int-keys', static fn (): mixed => response()->json(['10' => ['v' => 'a'], '11' => ['v' => 'b']]))->middleware('exporter.negotiate');
        $router->get('/legacy-nested', static fn (): mixed => response()->json([['id' => 1, 'link' => ['u' => 'a/b']]]))->middleware('exporter.negotiate');
        $router->get('/legacy-text', static fn (): mixed => response('plain text'))->middleware('exporter.negotiate');
        $router->get('/legacy-scalar', static fn (): mixed => response()->json(42))->middleware('exporter.negotiate');
        $router->get('/legacy-empty', static fn (): mixed => response()->json([]))->middleware('exporter.negotiate');
    }
}
