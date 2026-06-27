<?php

declare(strict_types = 1);

namespace Tests\Integration;

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
     * It returns itself when applying relation hints.
     *
     * @return void
     */
    public function testWithRelationsIsChainable(): void
    {
        $source = new QueryChunkSource(User::query());

        self::assertSame($source, $source->withRelations(['profile']));
    }
}
