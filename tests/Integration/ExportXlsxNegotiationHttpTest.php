<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Engine;
use SineMacula\Exporter\Http\Concerns\RespondsWithExports;
use SineMacula\Exporter\Http\ExportFormat;
use SineMacula\Exporter\Http\ExportNegotiator;
use SineMacula\Exporter\Http\ExportResourceCollection;
use SineMacula\Exporter\Http\MediaTypeRegistry;
use SineMacula\Exporter\Sinks\DiskSink;
use SineMacula\Exporter\Sinks\StreamedResponseSink;
use SineMacula\Exporter\Sources\ResourceCollectionSource;
use SineMacula\Exporter\Sources\ResourceItemSource;
use SineMacula\Exporter\Writers\XlsxWriter;
use Tests\Support\V3\Concerns\ReadsXlsx;
use Tests\Support\V3\ExporterTestCase;
use Tests\Support\V3\Models\User;
use Tests\Support\V3\Resources\PlainUserResource;
use Tests\Support\V3\Resources\UserResource;
use Tests\Support\V3\Schema\UserExportSchema;

/**
 * HTTP negotiation tests for the XLSX format, plus the disk-sink finalise path.
 *
 * Drives the negotiator over real routes, reads the generated workbook back
 * through the OpenSpout reader, and asserts the typed cells, headers, and the
 * 406 raised when a resource has no tabular schema. The disk-sink test covers
 * the non-seekable finalise-then-putFromFile path end to end.
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
#[CoversClass(Engine::class)]
#[CoversClass(XlsxWriter::class)]
#[CoversClass(StreamedResponseSink::class)]
#[CoversClass(DiskSink::class)]
#[CoversClass(ResourceItemSource::class)]
#[CoversClass(ResourceCollectionSource::class)]
final class ExportXlsxNegotiationHttpTest extends ExporterTestCase
{
    use ReadsXlsx;

    /** The XLSX vendor media type negotiated for the format. */
    private const string XLSX_MEDIA_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * It streams a collection as a typed XLSX workbook for the Accept header.
     *
     * @return void
     */
    public function testCollectionNegotiatesXlsxByAcceptHeader(): void
    {
        $this->seedUsers(2);

        $response = $this->get('/users', ['Accept' => self::XLSX_MEDIA_TYPE]);

        $response->assertOk();
        $response->assertHeader('Content-Type', self::XLSX_MEDIA_TYPE . '; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename=users.xlsx');
        $response->assertHeader('Vary', 'Accept');

        $rows = $this->readWorkbookFromString($response->streamedContent());

        self::assertCount(3, $rows);
        self::assertSame(['ID', 'Name', 'Email', 'Active', 'Joined'], $rows[0]);

        [$id, $name, $email, $active, $joined] = $rows[1];

        self::assertIsInt($id);
        self::assertSame(1, $id);
        self::assertSame('User 1', $name);
        self::assertSame('user1@example.test', $email);
        self::assertSame('Yes', $active);
        self::assertInstanceOf(\DateTimeInterface::class, $joined);
        self::assertSame('2026-01-01', $joined->format('Y-m-d'));
    }

    /**
     * It streams a collection as XLSX for the explicit format parameter.
     *
     * @return void
     */
    public function testCollectionNegotiatesXlsxByFormatParameter(): void
    {
        $this->seedUsers(2);

        $response = $this->get('/users?format=xlsx');

        $response->assertOk();
        $response->assertHeader('Content-Type', self::XLSX_MEDIA_TYPE . '; charset=UTF-8');

        $rows = $this->readWorkbookFromString($response->streamedContent());

        self::assertCount(3, $rows);
        self::assertSame(['ID', 'Name', 'Email', 'Active', 'Joined'], $rows[0]);
    }

    /**
     * It negotiates a single top-level item as a one-row XLSX workbook.
     *
     * @return void
     */
    public function testSingleItemNegotiatesXlsx(): void
    {
        $this->seedUsers(1);

        $response = $this->get('/user', ['Accept' => self::XLSX_MEDIA_TYPE]);

        $response->assertOk();
        $response->assertHeader('Content-Type', self::XLSX_MEDIA_TYPE . '; charset=UTF-8');

        $rows = $this->readWorkbookFromString($response->streamedContent());

        self::assertCount(2, $rows);
        self::assertSame(['ID', 'Name', 'Email', 'Active', 'Joined'], $rows[0]);
        self::assertSame('User 1', $rows[1][1]);
    }

    /**
     * It returns 406 for a collection whose item has no tabular schema.
     *
     * @return void
     */
    public function testCollectionWithoutSchemaReturns406(): void
    {
        $this->seedUsers(1);

        $this->get('/plain', ['Accept' => self::XLSX_MEDIA_TYPE])->assertStatus(406);
    }

    /**
     * It finalises a typed XLSX workbook onto a non-seekable disk sink and
     * reads it back from the disk.
     *
     * @return void
     */
    public function testDiskSinkProducesReadableWorkbook(): void
    {
        $this->seedUsers(2);

        Storage::fake('local');

        $disk    = Storage::disk('local');
        $request = Request::create('/');
        $users   = User::query()->orderBy('id')->get(); // @phpstan-ignore staticMethod.dynamicCall
        $sink    = new DiskSink($disk, 'exports/users.xlsx');

        (new Engine)->export(
            new ResourceCollectionSource(UserResource::collection($users)),
            new UserExportSchema($request),
            $request,
            new XlsxWriter,
            $sink,
        );

        $disk->assertExists('exports/users.xlsx');

        $rows = $this->readWorkbook($disk->path('exports/users.xlsx'));

        self::assertCount(3, $rows);
        self::assertSame(['ID', 'Name', 'Email', 'Active', 'Joined'], $rows[0]);
        self::assertSame('User 1', $rows[1][1]);
        self::assertSame('User 2', $rows[2][1]);
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
    }
}
