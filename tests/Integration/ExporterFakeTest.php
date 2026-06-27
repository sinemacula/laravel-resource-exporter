<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Http\Resources\Json\ResourceCollection;
use PHPUnit\Framework\Assert as PHPUnit;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Export\ExportSpecification;
use SineMacula\Exporter\Facades\Exporter;
use SineMacula\Exporter\Testing\ExporterFake;
use SineMacula\Exporter\Testing\RecordedExport;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\V3\Models\User;
use Tests\Support\V3\QueuedExportTestCase;
use Tests\Support\V3\Resources\UserResource;
use Tests\Support\V3\Schema\UserExportSchema;

/**
 * Integration tests for the recording double installed by Exporter::fake().
 *
 * Proves that while the fake is bound every terminal verb records what it would
 * have exported instead of producing bytes, dispatching a job, or touching a
 * disk, and that the ledger assertions behave in both their passing and failing
 * directions.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExporterFake::class)]
#[CoversClass(RecordedExport::class)]
#[CoversClass(Exporter::class)]
final class ExporterFakeTest extends QueuedExportTestCase
{
    /**
     * It returns the fake from fake() so assertions can be chained.
     *
     * @return void
     */
    public function testFakeReturnsTheRecordingDouble(): void
    {
        $fake = Exporter::fake();

        self::assertInstanceOf(ExporterFake::class, $fake);
        self::assertSame($fake, ExporterFake::active());
    }

    /**
     * It records a download instead of producing bytes.
     *
     * @return void
     */
    public function testDownloadIsRecordedAndProducesNoBytes(): void
    {
        $this->seedUsers(2);

        $fake = Exporter::fake();

        $response = Exporter::collection($this->collection())->format('csv')->download('members');

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame('', $this->streamToString($response));

        $fake->assertDownloaded()
            ->assertDownloaded('members')
            ->assertDownloaded('members.csv')
            ->assertExportedRows(2);
    }

    /**
     * It fails assertDownloaded when the filename does not match.
     *
     * @return void
     */
    public function testAssertDownloadedFailsForAnUnknownFilename(): void
    {
        $this->seedUsers(1);

        $fake = Exporter::fake();

        Exporter::collection($this->collection())->download('members');

        $this->assertFails(static fn (): mixed => $fake->assertDownloaded('other'));
    }

    /**
     * It records a stored export and never touches the disk.
     *
     * @return void
     */
    public function testStoreIsRecordedAndDoesNotTouchTheDisk(): void
    {
        $this->seedUsers(2);

        $disk = $this->fakeDisk('exports');

        $fake = Exporter::fake();

        $path = Exporter::collection($this->collection())->format('csv')->store('exports', 'reports/users.csv');

        self::assertSame('reports/users.csv', $path);

        $disk->assertMissing('reports/users.csv');

        $fake->assertStored()
            ->assertStored('exports')
            ->assertStored('exports', 'reports/users.csv')
            ->assertExportedRows(2);
    }

    /**
     * It fails assertStored when the disk or path does not match.
     *
     * @return void
     */
    public function testAssertStoredFailsForAnUnknownTarget(): void
    {
        $this->seedUsers(1);

        $fake = Exporter::fake();

        Exporter::collection($this->collection())->store('exports', 'reports/users.csv');

        $this->assertFails(static fn (): mixed => $fake->assertStored('other-disk'));
        $this->assertFails(static fn (): mixed => $fake->assertStored('exports', 'reports/other.csv'));
    }

    /**
     * It records a buffered-to-string export and produces no bytes.
     *
     * @return void
     */
    public function testToStringIsRecorded(): void
    {
        $this->seedUsers(3);

        $fake = Exporter::fake();

        $bytes = Exporter::collection($this->collection())->toString();

        self::assertSame('', $bytes);

        $fake->assertExportedRows(3);
    }

    /**
     * It records a queued export instead of dispatching the job for real.
     *
     * @return void
     */
    public function testQueueIsRecorded(): void
    {
        $disk = $this->fakeDisk('exports');

        $fake = Exporter::fake();

        Exporter::queue(User::class, UserResource::class)
            ->schema(UserExportSchema::class)
            ->format('csv')
            ->toDisk('exports', 'exports/users.csv')
            ->queue();

        $disk->assertMissing('exports/users.csv');

        $fake->assertQueued()
            ->assertQueued(static fn (ExportSpecification $spec): bool => $spec->model === User::class
                && $spec->format                                                       === 'csv'
                && $spec->disk                                                         === 'exports'
                && $spec->path                                                         === 'exports/users.csv');
    }

    /**
     * It fails assertQueued when no specification matches the predicate.
     *
     * @return void
     */
    public function testAssertQueuedFailsWhenNothingMatches(): void
    {
        $fake = Exporter::fake();

        Exporter::queue(User::class, UserResource::class)
            ->toDisk('exports', 'exports/users.csv')
            ->queue();

        $this->assertFails(static fn (): mixed => $fake->assertQueued(static fn (ExportSpecification $spec): bool => $spec->format === 'xlsx'));
    }

    /**
     * It asserts the exported row total by value and by predicate.
     *
     * @return void
     */
    public function testAssertExportedRowsSupportsValuesAndPredicates(): void
    {
        $this->seedUsers(4);

        $fake = Exporter::fake();

        Exporter::collection($this->collection())->download();

        $fake->assertExportedRows(4)
            ->assertExportedRows(static fn (int $total): bool => $total === 4);

        $this->assertFails(static fn (): mixed => $fake->assertExportedRows(99));
        $this->assertFails(static fn (): mixed => $fake->assertExportedRows(static fn (int $total): bool => $total === 0));
    }

    /**
     * It asserts that nothing was exported, and fails once something is.
     *
     * @return void
     */
    public function testAssertNothingExported(): void
    {
        $this->seedUsers(1);

        $fake = Exporter::fake();

        $fake->assertNothingExported();

        Exporter::collection($this->collection())->download();

        $this->assertFails(static fn (): mixed => $fake->assertNothingExported());
    }

    /**
     * Assert that the given assertion closure fails with an assertion error.
     *
     * @param  \Closure(): mixed  $assertion
     * @return void
     */
    private function assertFails(\Closure $assertion): void
    {
        try {
            $assertion();
        } catch (AssertionFailedError) {
            PHPUnit::assertTrue(true);

            return;
        }

        self::fail('Expected the assertion to fail, but it passed.');
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
}
