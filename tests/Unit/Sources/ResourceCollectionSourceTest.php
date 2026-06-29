<?php

declare(strict_types = 1);

namespace Tests\Unit\Sources;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Sources\ResourceCollectionSource;
use Tests\Support\Resources\LeakyResource;

/**
 * Tests for the resource collection source adapter.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ResourceCollectionSource::class)]
final class ResourceCollectionSourceTest extends TestCase
{
    /**
     * Bind a request so resource collections resolve.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container;
        $container->instance('request', Request::create('/'));

        Container::setInstance($container);
    }

    /**
     * Tear down the shared container.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }

    /**
     * It yields the underlying items unwrapped from each resource.
     *
     * @return void
     */
    public function testYieldsUnderlyingItems(): void
    {
        $items      = [['id' => 1], ['id' => 2], ['id' => 3]];
        $collection = LeakyResource::collection(new Collection($items));

        $source = new ResourceCollectionSource($collection);

        self::assertSame($items, iterator_to_array($source->rows(), false));
    }

    /**
     * It iterates without ever serialising the resources (no toArray/resolve).
     *
     * The item resource's toArray() throws; if the source materialised the
     * collection it would trip that throw. Streaming the underlying items
     * proves rows never route through resolve()/toArray().
     *
     * @return void
     */
    public function testDoesNotMaterialiseTheCollection(): void
    {
        $collection = LeakyResource::collection(new Collection([['id' => 1], ['id' => 2]]));

        $source = new ResourceCollectionSource($collection);

        $seen = [];

        foreach ($source->rows() as $item) {
            $seen[] = $item['id'];
        }

        self::assertSame([1, 2], $seen);
    }

    /**
     * It returns itself when applying relation hints.
     *
     * @return void
     */
    public function testWithRelationsIsChainable(): void
    {
        $collection = LeakyResource::collection(new Collection([['id' => 1]]));
        $source     = new ResourceCollectionSource($collection);

        self::assertSame($source, $source->withRelations(['profile']));
    }
}
