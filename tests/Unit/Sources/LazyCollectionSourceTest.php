<?php

declare(strict_types = 1);

namespace Tests\Unit\Sources;

use Illuminate\Support\LazyCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Sources\LazyCollectionSource;
use Tests\Support\ExporterTestCase;
use Tests\Support\Models\User;

/**
 * Tests for the lazy collection source adapter's chunked eager-loading.
 *
 * When relations apply, the adapter chunks the lazy stream and eager-loads the
 * requested relations across each chunk's models, tolerating non-model entries.
 * This proves a non-model entry earlier in a chunk does not stop a later model
 * from loading.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(LazyCollectionSource::class)]
final class LazyCollectionSourceTest extends ExporterTestCase
{
    /**
     * It eager-loads a model even when a non-model precedes it in the chunk.
     *
     * @return void
     */
    public function testEagerLoadsAModelPrecededByANonModel(): void
    {
        $this->seedUsers(1);
        $this->seedOrders(1, [5]);

        /** @var \Tests\Support\Models\User $user */
        $user = User::query()->firstOrFail(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertFalse($user->relationLoaded('orders'));

        $source = (new LazyCollectionSource(LazyCollection::make([1, $user]), chunkSize: 2))->withRelations(['orders']);

        iterator_to_array($source->rows(), false);

        self::assertTrue($user->relationLoaded('orders'), 'A non-model earlier in the chunk must not stop the later model from loading.');
    }
}
