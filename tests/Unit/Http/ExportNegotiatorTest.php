<?php

declare(strict_types = 1);

namespace Tests\Unit\Http;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Http\ExportFormat;
use SineMacula\Exporter\Http\ExportNegotiator;
use SineMacula\Exporter\Http\FormatResolver;
use SineMacula\Exporter\Http\MediaTypeRegistry;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests for the custom negotiation resolver and media registry.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExportNegotiator::class)]
#[CoversClass(FormatResolver::class)]
#[CoversClass(MediaTypeRegistry::class)]
#[CoversClass(ExportFormat::class)]
final class ExportNegotiatorTest extends TestCase
{
    /**
     * Accept-header negotiation cases.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function acceptHeaders(): iterable
    {
        yield 'explicit csv' => ['text/csv', 'csv'];
        yield 'explicit json' => ['application/json', 'json'];
        yield 'wildcard is json' => ['*/*', 'json'];
        yield 'q=0 is filtered out' => ['text/csv;q=0, application/json', 'json'];
        yield 'highest quality wins' => ['application/json, text/csv;q=0.9', 'json'];
        yield 'unknown falls to json' => ['text/html', 'json'];
        yield 'browser default prefers json over lower-q xml' => ['text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'json'];
        yield 'lower-q registered type loses to higher-q unknown' => ['text/html, text/csv;q=0.9', 'json'];
        yield 'application wildcard resolves to the json default' => ['application/*', 'json'];
        yield 'text wildcard resolves to the first text format' => ['text/*', 'csv'];
        yield 'unmatched type wildcard falls back to json' => ['audio/*', 'json'];
        yield 'blank accept falls back to json' => ['   ', 'json'];
    }

    /**
     * It resolves the negotiated format from the Accept header.
     *
     * @param  string  $accept
     * @param  string  $expected
     * @return void
     */
    #[DataProvider('acceptHeaders')]
    public function testResolvesFromAcceptHeader(string $accept, string $expected): void
    {
        $request = Request::create('/', server: ['HTTP_ACCEPT' => $accept]);

        self::assertSame($expected, (new ExportNegotiator)->resolve($request));
    }

    /**
     * It defaults to JSON when the Accept header is empty.
     *
     * @return void
     */
    public function testEmptyAcceptDefaultsToJson(): void
    {
        self::assertSame('json', (new ExportNegotiator)->resolve(Request::create('/')));
    }

    /**
     * It prefers an explicit, whitelisted query parameter over the Accept
     * header.
     *
     * @return void
     */
    public function testQueryParameterTakesPrecedence(): void
    {
        $request = Request::create('/?format=csv', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertSame('csv', (new ExportNegotiator)->resolve($request));
    }

    /**
     * It ignores a query parameter that is not a registered format.
     *
     * @return void
     */
    public function testUnknownQueryParameterIsIgnored(): void
    {
        $request = Request::create('/?format=pdf', server: ['HTTP_ACCEPT' => 'text/csv']);

        self::assertSame('csv', (new ExportNegotiator)->resolve($request));
    }

    /**
     * It resolves the format from a whitelisted URL extension.
     *
     * @return void
     */
    public function testResolvesFromUrlExtension(): void
    {
        self::assertSame('tsv', (new ExportNegotiator)->resolve(Request::create('/report.tsv')));
        self::assertSame('csv', (new ExportNegotiator)->resolve(Request::create('/report.csv')));
    }

    /**
     * It reports tabular formats and not the hierarchical default.
     *
     * @return void
     */
    public function testIsTabular(): void
    {
        $negotiator = new ExportNegotiator;

        self::assertTrue($negotiator->isTabular('csv'));
        self::assertTrue($negotiator->isTabular('tsv'));
        self::assertFalse($negotiator->isTabular('json'));
    }

    /**
     * It registers a custom format and exposes the registry surface.
     *
     * @return void
     */
    public function testRegistrySurfaceAndDefault(): void
    {
        $registry = new MediaTypeRegistry;

        self::assertSame('json', $registry->defaultFormat());
        self::assertNotEmpty($registry->all());
        self::assertContainsOnlyInstancesOf(ExportFormat::class, $registry->all());

        $registry->register(new ExportFormat('pdf', 'pdf', 'application/pdf', ['application/pdf'], false));
        $registry->setDefault('pdf');

        self::assertSame('pdf', $registry->defaultFormat());
        self::assertTrue($registry->has('pdf'));

        $format = $registry->get('pdf');

        self::assertInstanceOf(ExportFormat::class, $format);
        self::assertSame('application/pdf', $format->defaultMediaType());
        self::assertSame('pdf', $registry->formatForMediaType('application/pdf; charset=utf-8'));
        self::assertSame('pdf', $registry->formatForExtension('pdf'));
        self::assertNull($format->writer());
        self::assertNull($format->hierarchicalWriter());
    }

    /**
     * It adds Accept to the Vary header without duplicating it.
     *
     * @return void
     */
    public function testVaryAcceptIsIdempotent(): void
    {
        $response = new Response;

        ExportNegotiator::varyAccept($response);
        ExportNegotiator::varyAccept($response);

        self::assertSame(['Accept'], $response->getVary());
    }
}
