<?php

declare(strict_types = 1);

namespace Tests\Unit\Http;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Http\ExportFormat;
use SineMacula\Exporter\Http\FormatResolver;
use SineMacula\Exporter\Http\MediaTypeRegistry;

/**
 * Tests for the standalone export format resolver.
 *
 * Exercises the resolver's public surface directly: format precedence across
 * the ?format= parameter, a whitelisted URL extension and the Accept header,
 * and the explicit-format predicate the negotiator uses to decide whether to
 * defer the default JSON representation to the resource's native response.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FormatResolver::class)]
#[CoversClass(MediaTypeRegistry::class)]
#[CoversClass(ExportFormat::class)]
final class FormatResolverTest extends TestCase
{
    /**
     * Explicit-format cases: each request and whether a format was forced.
     *
     * @return iterable<string, array{\Illuminate\Http\Request, bool}>
     */
    public static function explicitFormatCases(): iterable
    {
        yield 'query parameter forces a format' => [Request::create('/users?format=csv'), true];
        yield 'whitelisted extension forces a format' => [Request::create('/users.csv'), true];
        yield 'unknown query parameter is not explicit' => [Request::create('/users?format=pdf'), false];
        yield 'accept header alone is not explicit' => [Request::create('/users', 'GET', server: ['HTTP_ACCEPT' => 'text/csv']), false];
        yield 'plain request is not explicit' => [Request::create('/users'), false];
    }

    /**
     * It detects whether the request explicitly selected a format.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  bool  $expected
     * @return void
     */
    #[DataProvider('explicitFormatCases')]
    public function testIsExplicitFormat(Request $request, bool $expected): void
    {
        self::assertSame($expected, (new FormatResolver)->isExplicitFormat($request));
    }

    /**
     * It resolves the explicit query parameter ahead of the Accept header.
     *
     * @return void
     */
    public function testQueryParameterTakesPrecedenceOverAccept(): void
    {
        $request = Request::create('/users?format=csv', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertSame('csv', (new FormatResolver)->resolve($request));
    }

    /**
     * It resolves the whitelisted URL extension ahead of the Accept header.
     *
     * @return void
     */
    public function testExtensionTakesPrecedenceOverAccept(): void
    {
        $request = Request::create('/users.tsv', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertSame('tsv', (new FormatResolver)->resolve($request));
    }

    /**
     * A starred Accept value that is not a "type/*" range falls to the default.
     *
     * @return void
     */
    public function testMalformedWildcardAcceptValueFallsBackToTheDefault(): void
    {
        $request = Request::create('/users', 'GET', server: ['HTTP_ACCEPT' => 'foo/*bar']);

        self::assertSame('json', (new FormatResolver)->resolve($request));
    }

    /**
     * The query parameter outranks a whitelisted URL extension when both are
     * present and disagree.
     *
     * @return void
     */
    public function testQueryParameterBeatsExtensionWhenBothPresent(): void
    {
        self::assertSame('csv', (new FormatResolver)->resolve(Request::create('/users.tsv?format=csv')));
    }

    /**
     * The query parameter is matched case-insensitively against the registry.
     *
     * @return void
     */
    public function testQueryParameterIsCaseInsensitive(): void
    {
        self::assertSame('csv', (new FormatResolver)->resolve(Request::create('/users?format=CSV')));
    }

    /**
     * A request with no Accept header at all resolves to the configured default
     * rather than erroring on the missing header.
     *
     * @return void
     */
    public function testAbsentAcceptHeaderResolvesToTheDefault(): void
    {
        $request = Request::create('/users');
        $request->headers->remove('Accept');

        self::assertSame('json', (new FormatResolver)->resolve($request));
    }

    /**
     * A "type/*" wildcard prefers the configured default format when its media
     * type sits in that type, ahead of the first registered match.
     *
     * @return void
     */
    public function testTypeWildcardPrefersTheConfiguredDefaultFormat(): void
    {
        $registry = (new MediaTypeRegistry)->setDefault('xml');
        $request  = Request::create('/users', 'GET', server: ['HTTP_ACCEPT' => 'application/*']);

        self::assertSame('xml', (new FormatResolver($registry))->resolve($request));
    }

    /**
     * A "type/*" wildcard matches on the full "type/" boundary, so a format
     * whose media type merely shares the type prefix is not mistaken for it.
     *
     * @return void
     */
    public function testTypeWildcardMatchesOnTheTrailingSlashBoundary(): void
    {
        $registry = (new MediaTypeRegistry)
            ->register(new ExportFormat('textual', 'txt', 'textual/plain', ['textual/plain'], false))
            ->setDefault('textual');

        $request = Request::create('/users', 'GET', server: ['HTTP_ACCEPT' => 'text/*']);

        self::assertSame('csv', (new FormatResolver($registry))->resolve($request));
    }
}
