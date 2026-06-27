<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Export\ExportSpecification;
use SineMacula\Exporter\Export\QueuedExport;
use SineMacula\Exporter\Jobs\ExportToDiskJob;
use Tests\Support\V3\Models\User;
use Tests\Support\V3\QueuedExportTestCase;
use Tests\Support\V3\Resources\UserResource;
use Tests\Support\V3\Schema\UserExportSchema;

/**
 * Integration tests for the fluent queued-export builder and its specification.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QueuedExport::class)]
#[CoversClass(ExportSpecification::class)]
final class QueuedExportTest extends QueuedExportTestCase
{
    /**
     * It dispatches the export-to-disk job from the queue() terminal verb.
     *
     * @return void
     */
    public function testQueueDispatchesTheExportToDiskJob(): void
    {
        Bus::fake();

        QueuedExport::forModel(User::class, UserResource::class)
            ->toDisk('exports', 'exports/users.csv')
            ->queue();

        Bus::assertDispatched(ExportToDiskJob::class);
    }

    /**
     * It refuses to build a specification without a target disk and path.
     *
     * @return void
     */
    public function testToSpecificationRequiresADiskAndPath(): void
    {
        $this->expectException(\LogicException::class);

        QueuedExport::forModel(User::class, UserResource::class)->toSpecification();
    }

    /**
     * It accumulates the builder state into a serializable specification.
     *
     * @return void
     */
    public function testToSpecificationCarriesTheBuilderState(): void
    {
        $actor = $this->seedActor('admin');

        $spec = QueuedExport::forModel(User::class, UserResource::class)
            ->schema(UserExportSchema::class)
            ->format('csv')
            ->toDisk('exports', 'exports/users.csv')
            ->where('active', true)
            ->orderBy('score', 'desc')
            ->limit(50)
            ->as('member-export')
            ->by($actor)
            ->authorize('export-users')
            ->chunk(250)
            ->progressEvery(100)
            ->expireAfter(15)
            ->toSpecification();

        self::assertSame(User::class, $spec->model);
        self::assertSame(UserResource::class, $spec->resource);
        self::assertSame(UserExportSchema::class, $spec->schema);
        self::assertSame('csv', $spec->format);
        self::assertSame('exports', $spec->disk);
        self::assertSame('exports/users.csv', $spec->path);
        self::assertSame('member-export', $spec->filename);
        self::assertSame($actor->id, $spec->actorId);
        self::assertSame($actor::class, $spec->actorClass);
        self::assertSame('export-users', $spec->ability);
        self::assertSame(250, $spec->chunkSize);
        self::assertSame(100, $spec->progressEvery);
        self::assertSame(15, $spec->urlExpiresAfter);
        self::assertContains(['type' => 'where', 'column' => 'active', 'operator' => '=', 'value' => true], $spec->constraints);
        self::assertContains(['type' => 'orderBy', 'column' => 'score', 'direction' => 'desc'], $spec->constraints);
        self::assertContains(['type' => 'limit', 'value' => 50], $spec->constraints);
    }

    /**
     * It survives a serialize()/unserialize() round-trip unchanged - the queue
     * serialization constraint the whole design turns on.
     *
     * @return void
     */
    public function testSpecificationSurvivesSerialization(): void
    {
        $spec = QueuedExport::forModel(User::class, UserResource::class)
            ->schema(UserExportSchema::class)
            ->toDisk('exports', 'exports/users.csv')
            ->where('role', 'user')
            ->whereIn('id', [1, 2, 3])
            ->orderBy('id')
            ->toSpecification();

        $restored = unserialize(serialize($spec));

        self::assertInstanceOf(ExportSpecification::class, $restored);
        self::assertSame($spec->model, $restored->model);
        self::assertSame($spec->resource, $restored->resource);
        self::assertSame($spec->schema, $restored->schema);
        self::assertSame($spec->format, $restored->format);
        self::assertSame($spec->disk, $restored->disk);
        self::assertSame($spec->path, $restored->path);
        self::assertEquals($spec->constraints, $restored->constraints);
    }

    /**
     * It rebuilds the query by replaying the simple where/order/limit
     * constraint descriptors.
     *
     * @return void
     */
    public function testQueryReplaysSimpleConstraints(): void
    {
        $this->seedUsers(10);

        $spec = QueuedExport::forModel(User::class, UserResource::class)
            ->toDisk('exports', 'exports/users.csv')
            ->where('score', '>=', 4)
            ->whereNotNull('email')
            ->orderBy('score', 'desc')
            ->limit(3)
            ->toSpecification();

        $scores = $spec->query()->pluck('score')->all();

        self::assertSame([10, 9, 8], $scores);
    }

    /**
     * It rebuilds the query by replaying a named Eloquent scope with arguments.
     *
     * @return void
     */
    public function testQueryReplaysANamedScope(): void
    {
        $this->seedUsers(10);

        $spec = QueuedExport::forModel(User::class, UserResource::class)
            ->toDisk('exports', 'exports/users.csv')
            ->scope('scoreAtLeast', 8)
            ->orderBy('id')
            ->toSpecification();

        $scores = $spec->query()->pluck('score')->all();

        self::assertSame([8, 9, 10], $scores);
    }

    /**
     * It ignores a scope constraint whose name is empty when replaying.
     *
     * @return void
     */
    public function testQueryIgnoresAnEmptyScopeName(): void
    {
        $this->seedUsers(3);

        $spec = QueuedExport::forModel(User::class, UserResource::class)
            ->toDisk('exports', 'exports/users.csv')
            ->scope('')
            ->orderBy('id')
            ->toSpecification();

        self::assertSame([1, 2, 3], $spec->query()->pluck('id')->all());
    }

    /**
     * It silently ignores a constraint descriptor of an unknown type when
     * rebuilding the query, leaving the base query untouched.
     *
     * @return void
     */
    public function testQueryIgnoresAnUnknownConstraintType(): void
    {
        $this->seedUsers(3);

        $spec = new ExportSpecification(
            model: User::class,
            resource: UserResource::class,
            schema: null,
            format: 'csv',
            disk: 'exports',
            path: 'exports/users.csv',
            constraints: [['type' => 'unsupported', 'column' => 'id']],
        );

        self::assertEqualsCanonicalizing([1, 2, 3], $spec->query()->pluck('id')->all());
    }

    /**
     * It replays a where-in constraint when rebuilding the query.
     *
     * @return void
     */
    public function testQueryReplaysAWhereInConstraint(): void
    {
        $this->seedUsers(5);

        $spec = QueuedExport::forModel(User::class, UserResource::class)
            ->toDisk('exports', 'exports/users.csv')
            ->whereIn('id', [2, 4])
            ->orderBy('id')
            ->toSpecification();

        self::assertSame([2, 4], $spec->query()->pluck('id')->all());
    }

    /**
     * It replays a where-null constraint when rebuilding the query.
     *
     * @return void
     */
    public function testQueryReplaysAWhereNullConstraint(): void
    {
        $this->seedUsers(3);

        User::query()->where('id', 2)->update(['secret' => null]); // @phpstan-ignore staticMethod.dynamicCall

        $spec = QueuedExport::forModel(User::class, UserResource::class)
            ->toDisk('exports', 'exports/users.csv')
            ->whereNull('secret')
            ->toSpecification();

        self::assertSame([2], $spec->query()->pluck('id')->all());
    }
}
