<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\LazyCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Sources\LazyCollectionSource;
use SineMacula\Exporter\Sources\PaginatorPageSource;
use SineMacula\Exporter\Sources\QueryChunkSource;
use SineMacula\Exporter\Sources\ResourceCollectionSource;
use SineMacula\Exporter\Sources\ResourceItemSource;
use SineMacula\Exporter\Sources\SourceFactory;
use Tests\Support\V3\ExporterTestCase;
use Tests\Support\V3\Models\User;
use Tests\Support\V3\Resources\UserResource;

/**
 * Integration tests for the remaining source adapters and the factory.
 *
 * Each adapter normalises a different export subject - a lazy collection, a
 * paginator page, a single resource - into the same lazy stream of underlying
 * domain items without serialising through the resource layer, eager-loading
 * the schema's requested relations per chunk where the items are models. These
 * tests drive the eager-load, non-model and chaining branches each adapter
 * guards, and assert the factory routes each subject to the matching adapter.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(LazyCollectionSource::class)]
#[CoversClass(PaginatorPageSource::class)]
#[CoversClass(ResourceItemSource::class)]
#[CoversClass(ResourceCollectionSource::class)]
#[CoversClass(SourceFactory::class)]
final class SourceAdaptersTest extends ExporterTestCase
{
    /**
     * It streams a lazy collection straight through when no relations apply.
     *
     * @return void
     */
    public function testLazyCollectionStreamsWithoutRelations(): void
    {
        $source = new LazyCollectionSource(LazyCollection::make([1, 2, 3]));

        self::assertSame([1, 2, 3], iterator_to_array($source->rows(), false));
    }

    /**
     * It eager-loads relations per chunk for the models in a lazy collection.
     *
     * @return void
     */
    public function testLazyCollectionEagerLoadsModelsPerChunk(): void
    {
        $this->seedUsers(5);
        $this->seedOrders(1, [10, 20]);

        $source = (new LazyCollectionSource(User::query()->cursor(), chunkSize: 2))
            ->withRelations(['orders']);

        $loaded = [];

        foreach ($source->rows() as $user) {
            self::assertInstanceOf(User::class, $user);
            $loaded[] = $user->relationLoaded('orders');
        }

        self::assertCount(5, $loaded);
        self::assertNotContains(false, $loaded, 'Every chunk must eager-load the requested relation.');
    }

    /**
     * It tolerates non-model items when eager-loading is requested.
     *
     * @return void
     */
    public function testLazyCollectionIgnoresNonModelItemsWhenLoading(): void
    {
        $source = (new LazyCollectionSource(LazyCollection::make([1, 2, 3]), chunkSize: 2))
            ->withRelations(['orders']);

        self::assertSame([1, 2, 3], iterator_to_array($source->rows(), false));
    }

    /**
     * It yields a paginator page and eager-loads its models.
     *
     * @return void
     */
    public function testPaginatorPageYieldsModelsAndEagerLoads(): void
    {
        $this->seedUsers(3);
        $this->seedOrders(1, [5]);

        /** @var \Illuminate\Pagination\LengthAwarePaginator<int, \Tests\Support\V3\Models\User> $page */
        $page   = User::query()->paginate(perPage: 2); // @phpstan-ignore staticMethod.dynamicCall
        $source = (new PaginatorPageSource($page))->withRelations(['orders']);

        $ids = [];

        foreach ($source->rows() as $user) {
            self::assertInstanceOf(User::class, $user);
            self::assertTrue($user->relationLoaded('orders'));
            $ids[] = $user->getKey();
        }

        self::assertSame([1, 2], $ids);
    }

    /**
     * It yields a page of non-model items without attempting to eager-load.
     *
     * @return void
     */
    public function testPaginatorPageYieldsNonModelItems(): void
    {
        $page   = new Paginator([1, 2, 3], perPage: 3, currentPage: 1);
        $source = (new PaginatorPageSource($page))->withRelations(['orders']);

        self::assertSame([1, 2, 3], iterator_to_array($source->rows(), false));
    }

    /**
     * It yields the underlying model of a single resource and eager-loads it.
     *
     * @return void
     */
    public function testResourceItemYieldsUnderlyingModel(): void
    {
        $this->seedUsers(1);
        $this->seedOrders(1, [7]);

        /** @var \Tests\Support\V3\Models\User $user */
        $user   = User::query()->first(); // @phpstan-ignore staticMethod.dynamicCall
        $source = (new ResourceItemSource(new UserResource($user)))->withRelations(['orders']);

        $items = iterator_to_array($source->rows(), false);

        self::assertCount(1, $items);
        self::assertInstanceOf(User::class, $items[0]);
        self::assertTrue($items[0]->relationLoaded('orders'));
    }

    /**
     * It yields a non-model resource subject without attempting to eager-load.
     *
     * @return void
     */
    public function testResourceItemYieldsNonModelSubject(): void
    {
        $source = (new ResourceItemSource(new JsonResource(['name' => 'plain'])))
            ->withRelations(['orders']);

        self::assertSame([['name' => 'plain']], iterator_to_array($source->rows(), false));
    }

    /**
     * It unwraps models from a resource collection and eager-loads them.
     *
     * @return void
     */
    public function testResourceCollectionUnwrapsAndEagerLoadsModels(): void
    {
        $this->seedUsers(2);
        $this->seedOrders(1, [3]);

        $collection = UserResource::collection(User::query()->get()); // @phpstan-ignore staticMethod.dynamicCall, argument.type
        $source     = (new ResourceCollectionSource($collection))->withRelations(['orders']);

        $ids = [];

        foreach ($source->rows() as $user) {
            self::assertInstanceOf(User::class, $user);
            self::assertTrue($user->relationLoaded('orders'));
            $ids[] = $user->getKey();
        }

        self::assertSame([1, 2], $ids);
    }

    /**
     * It routes each export subject to the matching source adapter.
     *
     * @return void
     */
    public function testFactoryRoutesEachSubject(): void
    {
        $this->seedUsers(1);

        /** @var \Tests\Support\V3\Models\User $user */
        $user = User::query()->first(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertInstanceOf(
            ResourceCollectionSource::class,
            SourceFactory::for(UserResource::collection(User::query()->get()), 100), // @phpstan-ignore staticMethod.dynamicCall, argument.type
        );
        self::assertInstanceOf(QueryChunkSource::class, SourceFactory::for(User::query(), 100));
        self::assertInstanceOf(ResourceItemSource::class, SourceFactory::for(new UserResource($user), 100));
    }
}
