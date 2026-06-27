<?php

declare(strict_types = 1);

namespace Tests\Unit\Export;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Export\HierarchicalRows;
use Tests\Support\V3\ArraySource;

/**
 * Tests the hierarchical row resolver.
 *
 * Lazily resolves each source item into the array shape the JSON, NDJSON and
 * XML writers consume: an item that is already a resource resolves itself; a
 * raw item is wrapped in the configured resource class first; and a raw item
 * with no resource class is a misconfiguration that throws. The count closure
 * fires once per item so a row count is reported without materialising the set.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(HierarchicalRows::class)]
final class HierarchicalRowsTest extends TestCase
{
    /**
     * It resolves an item that is already a resource and counts it.
     *
     * @return void
     */
    public function testResolvesAResourceItem(): void
    {
        $count   = 0;
        $rows    = new HierarchicalRows(null);
        $source  = new ArraySource([new JsonResource(['id' => 1]), new JsonResource(['id' => 2])]);
        $request = Request::create('/');

        $resolved = iterator_to_array($rows->resolve($source, $request, static function () use (&$count): void {
            $count++;
        }), false);

        self::assertSame([['id' => 1], ['id' => 2]], $resolved);
        self::assertSame(2, $count);
    }

    /**
     * It wraps a raw item in the configured resource class.
     *
     * @return void
     */
    public function testWrapsARawItemInTheResourceClass(): void
    {
        $rows   = new HierarchicalRows(JsonResource::class);
        $source = new ArraySource([['name' => 'Ada']]);

        $resolved = iterator_to_array($rows->resolve($source, Request::create('/'), static fn (): null => null), false);

        self::assertSame([['name' => 'Ada']], $resolved);
    }

    /**
     * It throws when a raw item has no resource class to wrap it.
     *
     * @return void
     */
    public function testThrowsForARawItemWithoutAResourceClass(): void
    {
        $rows   = new HierarchicalRows(null);
        $source = new ArraySource([['name' => 'Ada']]);

        $this->expectException(\LogicException::class);

        iterator_to_array($rows->resolve($source, Request::create('/'), static fn (): null => null), false);
    }
}
