<?php

declare(strict_types = 1);

namespace Tests\Unit\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Contracts\Source;
use SineMacula\Exporter\Exceptions\NoTabularRepresentation;
use SineMacula\Exporter\Http\ExportFormat;
use SineMacula\Exporter\Http\ExportNegotiator;
use SineMacula\Exporter\Http\FormatResolver;
use SineMacula\Exporter\Http\MediaTypeRegistry;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\TabularSchema;
use SineMacula\Exporter\Writers\CsvWriter;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\V3\ArraySource;
use Tests\Support\V3\Resources\PlainUserResource;
use Tests\Support\V3\Schema\FlexibleSchema;

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
        yield 'a sole q=0 candidate is rejected and falls to json' => ['text/csv;q=0', 'json'];
        yield 'an uppercase type wildcard resolves case-insensitively' => ['TEXT/*', 'csv'];
        yield 'the second equal-quality candidate is honoured' => ['text/html, text/csv', 'csv'];
        yield 'a leading bare wildcard resolves to json before a concrete type' => ['*, text/csv', 'json'];
        yield 'a starred value that is not a type range falls to json' => ['text/cs*', 'json'];
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
     * It defers a single item to its native JSON response when the default
     * format is negotiated without an explicit override.
     *
     * @return void
     */
    public function testItemDefersToNativeJsonByDefault(): void
    {
        $resource = new JsonResource(['id' => 1]);

        self::assertNull((new ExportNegotiator)->item($resource, Request::create('/')));
    }

    /**
     * It throws a 406 when streaming a tabular format that has no writer.
     *
     * @return void
     */
    public function testStreamExportThrowsWhenTheFormatHasNoWriter(): void
    {
        $registry = (new MediaTypeRegistry)
            ->register(new ExportFormat('weird', 'weird', 'application/x-weird', ['application/x-weird'], true));

        $source = new class implements Source {
            /**
             * Iterate the source as a lazy stream of domain items.
             *
             * @return iterable<int, mixed>
             */
            #[\Override]
            public function rows(): iterable
            {
                yield from [];
            }

            /**
             * Apply the eager-load hints the schema requested.
             *
             * @param  list<string>  $with
             * @return static
             */
            #[\Override]
            public function withRelations(array $with): static
            {
                return $this;
            }
        };

        $schema = new class (Request::create('/')) extends TabularSchema {
            /**
             * Get the ordered columns for the export.
             *
             * @return list<\SineMacula\Exporter\Schema\Column>
             */
            #[\Override]
            public function columns(): array
            {
                return [];
            }
        };

        $this->expectException(NoTabularRepresentation::class);

        (new ExportNegotiator($registry))->streamExport($source, $schema, 'weird', Request::create('/'));
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

        static::assertSame(['Accept'], $response->getVary());
    }

    /**
     * It appends Accept to a pre-existing Vary header without discarding the
     * values already present.
     *
     * @return void
     */
    public function testVaryAcceptPreservesExistingVaryValues(): void
    {
        $response = new Response;
        $response->setVary(['Accept-Encoding']);

        ExportNegotiator::varyAccept($response);

        static::assertSame(['Accept-Encoding', 'Accept'], $response->getVary());
    }

    /**
     * A tabular collection whose item resource provides no tabular schema 406s
     * naming the item resource, not the wrapping collection.
     *
     * @return void
     */
    public function testTabularCollectionWithoutSchemaNamesTheItemResource(): void
    {
        $collection = new ResourceCollection(collect([]));
        $request    = Request::create('/?format=csv');

        $this->expectException(NoTabularRepresentation::class);
        $this->expectExceptionMessage('Resource [' . PlainUserResource::class . '] has no tabular representation.');

        (new ExportNegotiator)->collection($collection, PlainUserResource::class, $request);
    }

    /**
     * It maps the built-in media types and extensions case-insensitively,
     * ignoring surrounding whitespace and media parameters.
     *
     * @return void
     */
    public function testBuiltInMediaTypeAndExtensionLookups(): void
    {
        $registry = new MediaTypeRegistry;

        static::assertSame('json', $registry->formatForMediaType('application/json'));
        static::assertSame('csv', $registry->formatForMediaType('TEXT/CSV'));
        static::assertSame('csv', $registry->formatForMediaType('  text/csv  ; charset=UTF-8'));
        static::assertSame('csv', $registry->formatForExtension('CSV'));
    }

    /**
     * Registration normalises both the media type and the extension to lower
     * case so a mixed-case registration is still resolvable.
     *
     * @return void
     */
    public function testRegisterNormalisesMediaTypeAndExtensionCase(): void
    {
        $registry = (new MediaTypeRegistry)
            ->register(new ExportFormat('weird', 'WEIRD', 'application/x-weird', ['APPLICATION/X-WEIRD'], false));

        static::assertSame('weird', $registry->formatForMediaType('application/x-weird'));
        static::assertSame('weird', $registry->formatForExtension('weird'));
    }

    /**
     * It exposes the registered formats as a zero-indexed list.
     *
     * @return void
     */
    public function testAllReturnsAReindexedList(): void
    {
        $formats = (new MediaTypeRegistry)->all();

        static::assertSame(range(0, count($formats) - 1), array_keys($formats));
    }

    /**
     * Lookups for an unregistered format name are null-safe: tabular reports
     * false and both writer factories return null rather than erroring.
     *
     * @return void
     */
    public function testUnknownFormatLookupsAreNullSafe(): void
    {
        $registry = new MediaTypeRegistry;

        static::assertFalse($registry->isTabular('nope'));
        static::assertNull($registry->writerFor('nope'));
        static::assertNull($registry->hierarchicalWriterFor('nope'));
    }

    /**
     * The Content-Disposition replaces path separators in the schema filename
     * with underscores so the header cannot be rejected.
     *
     * @return void
     */
    public function testDispositionSanitisesPathSeparatorsInTheFilename(): void
    {
        $request  = Request::create('/');
        $schema   = new FlexibleSchema($request, [Column::make('id', 'ID')], filename: 'reports/2026');
        $response = (new ExportNegotiator)->streamExport(new ArraySource([['id' => 1]]), $schema, 'csv', $request);

        static::assertSame('attachment; filename=reports_2026.csv', $response->headers->get('Content-Disposition'));
    }

    /**
     * The ASCII fallback filename strips the reserved percent character that
     * makeDisposition forbids in the fallback.
     *
     * @return void
     */
    public function testDispositionAsciiFallbackStripsReservedCharacters(): void
    {
        $request  = Request::create('/');
        $schema   = new FlexibleSchema($request, [Column::make('id', 'ID')], filename: 'a%b');
        $response = (new ExportNegotiator)->streamExport(new ArraySource([['id' => 1]]), $schema, 'csv', $request);

        static::assertSame('attachment; filename=a_b.csv; filename*=utf-8\'\'a%25b.csv', $response->headers->get('Content-Disposition'));
    }

    /**
     * The download filename takes the format's file extension, not the format
     * name, when the two differ.
     *
     * @return void
     */
    public function testDispositionUsesTheFormatExtensionNotItsName(): void
    {
        $registry = (new MediaTypeRegistry)
            ->register(new ExportFormat('report', 'csv', 'text/csv', ['text/csv'], true, static fn (): CsvWriter => new CsvWriter));

        $request  = Request::create('/');
        $schema   = new FlexibleSchema($request, [Column::make('id', 'ID')]);
        $response = (new ExportNegotiator($registry))->streamExport(new ArraySource([['id' => 1]]), $schema, 'report', $request);

        static::assertSame('attachment; filename=export.csv', $response->headers->get('Content-Disposition'));
    }
}
