<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Sources\QueryChunkSource;
use Tests\Support\V3\ExporterTestCase;
use Tests\Support\V3\Models\User;

/**
 * Integration tests for the keyset query-chunk source adapter.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QueryChunkSource::class)]
final class QueryChunkSourceTest extends ExporterTestCase
{
    /**
     * It streams every model in keyset order across chunk boundaries.
     *
     * @return void
     */
    public function testStreamsEveryModelInKeysetOrder(): void
    {
        $this->seedUsers(25);

        $source = new QueryChunkSource(User::query(), chunkSize: 10);

        $ids = [];

        foreach ($source->rows() as $user) {
            self::assertInstanceOf(User::class, $user);
            $ids[] = $user->id;
        }

        self::assertSame(range(1, 25), $ids);
    }

    /**
     * It streams every model in descending keyset order when requested.
     *
     * @return void
     */
    public function testStreamsEveryModelInDescendingKeysetOrder(): void
    {
        $this->seedUsers(5);

        $source = new QueryChunkSource(User::query()->orderBy('id', 'desc'), chunkSize: 2); // @phpstan-ignore staticMethod.dynamicCall

        $ids = [];

        foreach ($source->rows() as $user) {
            self::assertInstanceOf(User::class, $user);
            $ids[] = $user->id;
        }

        self::assertSame([5, 4, 3, 2, 1], $ids);
    }

    /**
     * It rejects non-key ordering because lazy keyset pagination cannot
     * preserve it safely.
     *
     * @return void
     */
    public function testRejectsNonKeyOrdering(): void
    {
        $this->seedUsers(5);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('score');

        $source = new QueryChunkSource(User::query()->orderBy('score', 'desc'), chunkSize: 2); // @phpstan-ignore staticMethod.dynamicCall

        iterator_to_array($source->rows(), false);
    }

    /**
     * It force-selects the key column so a constrained select cannot abort the
     * keyset stream mid-flight.
     *
     * lazyById() throws when the key is missing from a custom select(); the
     * adapter adds it back, so the stream completes and the models still carry
     * their key.
     *
     * @return void
     */
    public function testForceSelectsTheKeyColumnUnderAConstrainedSelect(): void
    {
        $this->seedUsers(5);

        $source = new QueryChunkSource(User::query()->select(['name']), chunkSize: 2); // @phpstan-ignore staticMethod.dynamicCall

        $ids   = [];
        $names = [];

        foreach ($source->rows() as $user) {
            self::assertInstanceOf(User::class, $user);
            $ids[]   = $user->getKey();
            $names[] = $user->name;
        }

        self::assertSame([1, 2, 3, 4, 5], $ids);
        self::assertSame(['User 1', 'User 2', 'User 3', 'User 4', 'User 5'], $names);
    }

    /**
     * It leaves a select that already names the key column untouched.
     *
     * @return void
     */
    public function testLeavesASelectThatAlreadyNamesTheKeyUntouched(): void
    {
        $this->seedUsers(3);

        $source = new QueryChunkSource(User::query()->select(['id', 'name']), chunkSize: 2); // @phpstan-ignore staticMethod.dynamicCall

        $ids = [];

        foreach ($source->rows() as $user) {
            self::assertInstanceOf(User::class, $user);
            self::assertArrayNotHasKey('email', $user->getAttributes(), 'A narrowed select must not hydrate columns it did not request.');
            $ids[] = $user->getKey();
        }

        self::assertSame([1, 2, 3], $ids);
    }

    /**
     * It force-selects the key past a raw, non-string select expression.
     *
     * @return void
     */
    public function testForceSelectsTheKeyPastARawSelectExpression(): void
    {
        $this->seedUsers(3);

        $source = new QueryChunkSource(User::query()->select([DB::raw('name')]), chunkSize: 2); // @phpstan-ignore staticMethod.dynamicCall

        $ids = [];

        foreach ($source->rows() as $user) {
            self::assertInstanceOf(User::class, $user);
            $ids[] = $user->getKey();
        }

        self::assertSame([1, 2, 3], $ids);
    }

    /**
     * It returns itself when applying relation hints.
     *
     * @return void
     */
    public function testWithRelationsIsChainable(): void
    {
        $source = new QueryChunkSource(User::query());

        self::assertSame($source, $source->withRelations(['profile']));
    }

    /**
     * It applies the requested relations to the query so each model loads them.
     *
     * @return void
     */
    public function testAppliesRequestedRelationsAsEagerLoads(): void
    {
        $this->seedUsers(3);
        $this->seedOrders(1, [5, 10]);

        $source = (new QueryChunkSource(User::query(), chunkSize: 2))->withRelations(['orders']);

        $seen = 0;

        foreach ($source->rows() as $user) {
            self::assertInstanceOf(User::class, $user);
            self::assertTrue($user->relationLoaded('orders'), 'Each streamed model must carry its eager-loaded relation.');
            $seen++;
        }

        self::assertSame(3, $seen);
    }

    /**
     * It does not append a duplicate key column to a select that names the key.
     *
     * @return void
     */
    public function testLeavesAKeyNamingSelectUntouched(): void
    {
        $this->seedUsers(3);

        $query = User::query(); // @phpstan-ignore staticMethod.dynamicCall
        $query->select(['id', 'name']); // @phpstan-ignore staticMethod.dynamicCall

        iterator_to_array((new QueryChunkSource($query, chunkSize: 2))->rows(), false);

        self::assertSame(['id', 'name'], $query->getQuery()->columns, 'A select already naming the key must not gain a qualified key column.');
    }

    /**
     * It recognises the qualified key column and leaves the select untouched.
     *
     * @return void
     */
    public function testLeavesAQualifiedKeySelectUntouched(): void
    {
        $this->seedUsers(3);

        $query = User::query(); // @phpstan-ignore staticMethod.dynamicCall
        $query->select(['users.id', 'name']); // @phpstan-ignore staticMethod.dynamicCall

        iterator_to_array((new QueryChunkSource($query, chunkSize: 2))->rows(), false);

        self::assertSame(['users.id', 'name'], $query->getQuery()->columns, 'The qualified key must be recognised so no duplicate is appended.');
    }

    /**
     * It skips a raw expression and still recognises the trailing key column.
     *
     * @return void
     */
    public function testForceSelectSkipsARawExpressionAndRecognisesTheKey(): void
    {
        $this->seedUsers(3);

        $query = User::query(); // @phpstan-ignore staticMethod.dynamicCall
        $query->select([DB::raw('name'), 'id']); // @phpstan-ignore staticMethod.dynamicCall

        iterator_to_array((new QueryChunkSource($query, chunkSize: 2))->rows(), false);

        self::assertCount(2, $query->getQuery()->columns, 'The raw expression must be skipped so the trailing key is still found.');
    }

    /**
     * It keysets the query with a default chunk size of exactly 1000.
     *
     * The chunk size reaches the database only through lazyById(), so capturing
     * that call pins the default precisely without seeding a thousand rows.
     *
     * @return void
     */
    public function testKeysetsAtTheDefaultChunkSize(): void
    {
        $base = User::query()->getQuery(); // @phpstan-ignore staticMethod.dynamicCall

        $builder = new class ($base) extends Builder {
            /** @var int|null The chunk size handed to lazyById() */
            public ?int $capturedChunkSize = null;

            /**
             * Capture the keyset chunk size instead of querying the database.
             *
             * @param  int  $chunkSize
             * @param  string|null  $column
             * @param  string|null  $alias
             * @return \Illuminate\Support\LazyCollection<int, mixed>
             */
            #[\Override]
            public function lazyById($chunkSize = 1000, $column = null, $alias = null): LazyCollection // phpcs:ignore Squiz.Commenting.FunctionComment.ScalarTypeHintMissing
            {
                $this->capturedChunkSize = $chunkSize;

                return LazyCollection::make([]);
            }
        };

        $builder->setModel(new User);

        iterator_to_array((new QueryChunkSource($builder))->rows(), false);

        self::assertSame(1000, $builder->capturedChunkSize);
    }
}
