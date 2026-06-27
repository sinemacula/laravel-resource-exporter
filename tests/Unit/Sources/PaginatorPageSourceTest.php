<?php

declare(strict_types = 1);

namespace Tests\Unit\Sources;

use Illuminate\Pagination\Paginator;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Sources\PaginatorPageSource;
use Tests\Support\V3\ExporterTestCase;
use Tests\Support\V3\Models\User;

/**
 * Tests for the paginator page source adapter's eager-loading.
 *
 * The adapter collects the page's model items and eager-loads the requested
 * relations across them, skipping non-model entries. This test proves a
 * non-model entry earlier in the page does not stop a later model from loading.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PaginatorPageSource::class)]
final class PaginatorPageSourceTest extends ExporterTestCase
{
    /**
     * It eager-loads a model even when a non-model precedes it on the page.
     *
     * @return void
     */
    public function testEagerLoadsAModelPrecededByANonModel(): void
    {
        $this->seedUsers(1);
        $this->seedOrders(1, [5]);

        /** @var \Tests\Support\V3\Models\User $user */
        $user = User::query()->firstOrFail(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertFalse($user->relationLoaded('orders'));

        $page   = new Paginator([1, $user], perPage: 2, currentPage: 1);
        $source = (new PaginatorPageSource($page))->withRelations(['orders']);

        iterator_to_array($source->rows(), false);

        self::assertTrue($user->relationLoaded('orders'), 'A non-model earlier on the page must not stop the later model from loading.');
    }
}
