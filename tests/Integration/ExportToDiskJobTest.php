<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Events\ExportCompleted;
use SineMacula\Exporter\Events\ExportFailed;
use SineMacula\Exporter\Events\ExportStarting;
use SineMacula\Exporter\Events\RowsExported;
use SineMacula\Exporter\Exceptions\NoTabularRepresentation;
use SineMacula\Exporter\Export\QueuedExport;
use SineMacula\Exporter\Jobs\ExportToDiskJob;
use Tests\Support\V3\Models\Actor;
use Tests\Support\V3\Models\User;
use Tests\Support\V3\QueuedExportTestCase;
use Tests\Support\V3\Resources\PlainUserResource;
use Tests\Support\V3\Resources\UserResource;
use Tests\Support\V3\Schema\ExplodingExportSchema;
use Tests\Support\V3\SignerlessDisk;

/**
 * Integration tests for the queued export-to-disk job.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExportToDiskJob::class)]
final class ExportToDiskJobTest extends QueuedExportTestCase
{
    /**
     * It streams the full query to the disk as a well-formed CSV file that can
     * be read back.
     *
     * @return void
     */
    public function testRunsTheJobAndStoresAWellFormedCsv(): void
    {
        $disk = $this->fakeDisk('exports');
        $this->seedUsers(25);

        ExportToDiskJob::dispatchSync(
            QueuedExport::forModel(User::class, UserResource::class)
                ->toDisk('exports', 'exports/users.csv')
                ->orderBy('id')
                ->chunk(10)
                ->toSpecification(),
        );

        $disk->assertExists('exports/users.csv');

        $rows = $this->readCsv($disk, 'exports/users.csv');

        self::assertSame(['ID', 'Name', 'Email', 'Active', 'Joined'], $rows[0]);
        self::assertCount(26, $rows);
        self::assertSame(['1', 'User 1', 'user1@example.test', 'Yes', '2026-01-01'], $rows[1]);
        self::assertSame(['25', 'User 25', 'user25@example.test', 'Yes', '2026-01-01'], $rows[25]);
    }

    /**
     * It generates a signed temporary URL for the stored file and carries it on
     * the completion event.
     *
     * @return void
     */
    public function testGeneratesASignedTemporaryUrlForTheStoredFile(): void
    {
        $disk = $this->fakeDisk('exports');
        $disk->buildTemporaryUrlsUsing(
            fn (string $path, mixed $expiration): string => 'https://signed.example/' . $path,
        );

        Event::fake();
        $this->seedUsers(3);

        ExportToDiskJob::dispatchSync(
            QueuedExport::forModel(User::class, UserResource::class)
                ->toDisk('exports', 'exports/users.csv')
                ->toSpecification(),
        );

        Event::assertDispatched(
            ExportCompleted::class,
            static fn (ExportCompleted $event): bool => $event->url === 'https://signed.example/exports/users.csv',
        );
    }

    /**
     * It completes with a null URL when generating the signed URL throws.
     *
     * @return void
     */
    public function testCompletesWithoutAUrlWhenSigningThrows(): void
    {
        $disk = $this->fakeDisk('exports');
        $disk->buildTemporaryUrlsUsing(static function (string $path, mixed $expiration): string {
            throw new \RuntimeException('no signing');
        });

        Event::fake();
        $this->seedUsers(2);

        ExportToDiskJob::dispatchSync(
            QueuedExport::forModel(User::class, UserResource::class)
                ->toDisk('exports', 'exports/users.csv')
                ->toSpecification(),
        );

        Event::assertDispatched(
            ExportCompleted::class,
            static fn (ExportCompleted $event): bool => $event->url === null && $event->rowCount === 2,
        );
    }

    /**
     * It completes with a null URL when the disk implementation exposes no
     * temporaryUrl() method at all - the non-standard-disk branch the standard
     * FilesystemAdapter (which always declares it) never reaches.
     *
     * @return void
     */
    public function testCompletesWithoutAUrlWhenTheDiskCannotSign(): void
    {
        $disk = new SignerlessDisk($this->fakeDisk('exports'));

        $factory = new readonly class ($disk) implements Factory {
            /**
             * Create a new single-disk filesystem factory.
             *
             * @param  \Illuminate\Contracts\Filesystem\Filesystem  $disk
             */
            public function __construct(

                /** The single signer-less disk every name resolves to. */
                private Filesystem $disk,
            ) {}

            /**
             * Resolve a filesystem disk by name.
             *
             * @param  mixed  $name
             * @return \Illuminate\Contracts\Filesystem\Filesystem
             */
            #[\Override]
            public function disk(mixed $name = null): Filesystem
            {
                return $this->disk;
            }
        };

        Event::fake();
        $this->seedUsers(2);

        $job = new ExportToDiskJob(
            QueuedExport::forModel(User::class, UserResource::class)
                ->toDisk('exports', 'exports/users.csv')
                ->toSpecification(),
        );

        app()->call([$job, 'handle'], ['filesystem' => $factory]);

        Event::assertDispatched(
            ExportCompleted::class,
            static fn (ExportCompleted $event): bool => $event->url === null && $event->rowCount === 2,
        );
    }

    /**
     * It fires ExportStarting and ExportCompleted with the expected payloads,
     * the audit event carrying the pinned actor_id/row_count/filename/format/
     * completed_at shape.
     *
     * @return void
     */
    public function testFiresStartingAndCompletedWithThePinnedAuditPayload(): void
    {
        $this->fakeDisk('exports');
        $admin = $this->seedActor('admin');

        Event::fake();
        $this->seedUsers(25);

        ExportToDiskJob::dispatchSync(
            QueuedExport::forModel(User::class, UserResource::class)
                ->toDisk('exports', 'exports/users.csv')
                ->as('members')
                ->by($admin)
                ->toSpecification(),
        );

        Event::assertDispatched(
            ExportStarting::class,
            static fn (ExportStarting $event): bool => $event->format === 'csv'
                && $event->disk                                       === 'exports'
                && $event->path                                       === 'exports/users.csv'
                && $event->actorId                                    === $admin->id,
        );

        Event::assertDispatched(
            ExportCompleted::class,
            static fn (ExportCompleted $event): bool => $event->actorId === $admin->id
                && $event->rowCount                                     === 25
                && $event->filename                                     === 'members'
                && $event->format                                       === 'csv'
                && $event->completedAt->toDateString()                  === now()->toDateString()
                && $event->disk                                         === 'exports'
                && $event->path                                         === 'exports/users.csv',
        );
    }

    /**
     * It fires periodic progress events as rows stream to disk.
     *
     * @return void
     */
    public function testFiresProgressEventsAsRowsStream(): void
    {
        $this->fakeDisk('exports');
        Event::fake();
        $this->seedUsers(25);

        ExportToDiskJob::dispatchSync(
            QueuedExport::forModel(User::class, UserResource::class)
                ->toDisk('exports', 'exports/users.csv')
                ->chunk(5)
                ->progressEvery(10)
                ->toSpecification(),
        );

        Event::assertDispatchedTimes(RowsExported::class, 2);
        Event::assertDispatched(RowsExported::class, static fn (RowsExported $event): bool => $event->rows === 10);
        Event::assertDispatched(RowsExported::class, static fn (RowsExported $event): bool => $event->rows === 20);
    }

    /**
     * It re-checks full-set authorization on the worker and rejects a
     * forbidden actor before any file is stored - the shared authz check
     * exercised through the queued front door.
     *
     * @return void
     */
    public function testForbiddenActorIsRejectedOnTheQueuedPath(): void
    {
        $disk = $this->fakeDisk('exports');
        Gate::define('export-users', static fn (Actor $actor, string $model): bool => $actor->name === 'admin');

        $mortal = $this->seedActor('mortal');
        $this->seedUsers(3);

        $job = new ExportToDiskJob(
            QueuedExport::forModel(User::class, UserResource::class)
                ->toDisk('exports', 'exports/users.csv')
                ->by($mortal)
                ->authorize('export-users')
                ->toSpecification(),
        );

        $caught = null;

        try {
            app()->call([$job, 'handle']);
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(AuthorizationException::class, $caught);
        self::assertFalse($disk->exists('exports/users.csv'));
    }

    /**
     * It lets an authorized actor through the full-set re-check and completes
     * the export.
     *
     * @return void
     */
    public function testAuthorizedActorCompletesTheExport(): void
    {
        $disk = $this->fakeDisk('exports');
        Gate::define('export-users', static fn (Actor $actor, string $model): bool => $actor->name === 'admin');

        $admin = $this->seedActor('admin');

        Event::fake();
        $this->seedUsers(3);

        ExportToDiskJob::dispatchSync(
            QueuedExport::forModel(User::class, UserResource::class)
                ->toDisk('exports', 'exports/users.csv')
                ->by($admin)
                ->authorize('export-users')
                ->toSpecification(),
        );

        $disk->assertExists('exports/users.csv');

        Event::assertDispatched(
            ExportCompleted::class,
            static fn (ExportCompleted $event): bool => $event->actorId === $admin->id && $event->rowCount === 3,
        );
    }

    /**
     * It rejects a non-tabular format before producing any output.
     *
     * @return void
     */
    public function testRejectsANonTabularFormat(): void
    {
        $this->fakeDisk('exports');
        $this->seedUsers(1);

        $job = new ExportToDiskJob(
            QueuedExport::forModel(User::class, UserResource::class)
                ->format('json')
                ->toDisk('exports', 'exports/users.json')
                ->toSpecification(),
        );

        $this->expectException(NoTabularRepresentation::class);

        app()->call([$job, 'handle']);
    }

    /**
     * It rejects a resource that declares no tabular schema.
     *
     * @return void
     */
    public function testRejectsAResourceWithoutATabularSchema(): void
    {
        $this->fakeDisk('exports');
        $this->seedUsers(1);

        $job = new ExportToDiskJob(
            QueuedExport::forModel(User::class, PlainUserResource::class)
                ->toDisk('exports', 'exports/users.csv')
                ->toSpecification(),
        );

        $this->expectException(NoTabularRepresentation::class);

        app()->call([$job, 'handle']);
    }

    /**
     * It fires ExportFailed - retry-safe - once the job exhausts its retries.
     *
     * @return void
     */
    public function testFailedFiresExportFailed(): void
    {
        $admin = $this->seedActor('admin');

        Event::fake();

        $job = new ExportToDiskJob(
            QueuedExport::forModel(User::class, UserResource::class)
                ->toDisk('exports', 'exports/users.csv')
                ->by($admin)
                ->toSpecification(),
        );

        $exception = new \RuntimeException('export blew up');

        $job->failed($exception);

        Event::assertDispatched(
            ExportFailed::class,
            static fn (ExportFailed $event): bool => $event->format === 'csv'
                && $event->disk                                     === 'exports'
                && $event->path                                     === 'exports/users.csv'
                && $event->actorId                                  === $admin->id
                && $event->exception                                === $exception,
        );
    }

    /**
     * It cleans up the staging file and removes any partial disk file after a
     * mid-stream failure, leaving a retry a clean slate.
     *
     * @return void
     */
    public function testCleansUpAfterAMidStreamFailure(): void
    {
        $disk = $this->fakeDisk('exports');
        $this->seedUsers(3);

        $before = $this->stagingTempFiles();

        $job = new ExportToDiskJob(
            QueuedExport::forModel(User::class, UserResource::class)
                ->schema(ExplodingExportSchema::class)
                ->toDisk('exports', 'exports/boom.csv')
                ->toSpecification(),
        );

        $caught = null;

        try {
            app()->call([$job, 'handle']);
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $caught);
        self::assertEmpty(array_diff($this->stagingTempFiles(), $before));
        self::assertFalse($disk->exists('exports/boom.csv'));
    }

    /**
     * It leaves no staging file behind after a successful export.
     *
     * @return void
     */
    public function testCleansUpTheStagingFileOnSuccess(): void
    {
        $disk = $this->fakeDisk('exports');
        $this->seedUsers(3);

        $before = $this->stagingTempFiles();

        ExportToDiskJob::dispatchSync(
            QueuedExport::forModel(User::class, UserResource::class)
                ->toDisk('exports', 'exports/users.csv')
                ->toSpecification(),
        );

        self::assertEmpty(array_diff($this->stagingTempFiles(), $before));
        $disk->assertExists('exports/users.csv');
    }

    /**
     * Read a stored CSV file back into a list of parsed rows.
     *
     * Parsed with the same enclosure and (empty) escape the writer emits, so a
     * quoted value round-trips back to its raw string.
     *
     * @param  \Illuminate\Filesystem\FilesystemAdapter  $disk
     * @param  string  $path
     * @return list<list<string|null>>
     */
    private function readCsv(FilesystemAdapter $disk, string $path): array
    {
        $contents = (string) $disk->get($path);

        $lines = array_filter(explode("\n", trim($contents)), static fn (string $line): bool => $line !== '');

        return array_values(array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), $lines));
    }
}
