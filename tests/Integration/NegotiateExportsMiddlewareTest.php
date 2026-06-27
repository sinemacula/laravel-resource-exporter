<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Http\Middleware\NegotiateExports;
use Tests\Support\V3\ExporterTestCase;

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
    }
}
