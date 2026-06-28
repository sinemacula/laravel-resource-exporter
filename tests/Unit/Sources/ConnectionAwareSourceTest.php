<?php

declare(strict_types = 1);

namespace Tests\Unit\Sources;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Schema\EagerLoadPlan;
use SineMacula\Exporter\Sources\ConnectionAwareSource;
use Tests\Support\V3\AggregateRecordingSource;
use Tests\Support\V3\ArraySource;

/**
 * Tests the connection-aware source decorator.
 *
 * The decorator streams its wrapped source until an abort signal reports the
 * client has disconnected, then stops cleanly, so a streamed export abandons an
 * expensive query the moment the browser goes away. It is transparent
 * otherwise: eager-load hints and, where supported, the aggregate plan are
 * forwarded to the wrapped source. These tests drive it with a stub abort
 * signal so the disconnect is deterministic rather than reliant on a real
 * socket.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ConnectionAwareSource::class)]
final class ConnectionAwareSourceTest extends TestCase
{
    /**
     * It yields every item while the client stays connected.
     *
     * @return void
     */
    public function testYieldsEveryItemWhileConnected(): void
    {
        $source = new ConnectionAwareSource(new ArraySource([1, 2, 3]), static fn (): bool => false);

        self::assertSame([1, 2, 3], iterator_to_array($source->rows(), false));
    }

    /**
     * It stops iterating the moment the abort signal fires.
     *
     * @return void
     */
    public function testStopsIteratingWhenTheClientDisconnects(): void
    {
        $calls  = 0;
        $source = new ConnectionAwareSource(new ArraySource([1, 2, 3, 4, 5]), static function () use (&$calls): bool {
            return ++$calls > 2;
        });

        self::assertSame([1, 2], iterator_to_array($source->rows(), false), 'Iteration must stop after the third abort check reports a disconnect.');
    }

    /**
     * It stops outright on disconnect rather than skipping the aborted item.
     *
     * The abort signal fires for a single check only; a decorator that merely
     * skipped that item would resume and yield the rest, so this pins that the
     * stream truly halts the moment the disconnect is seen.
     *
     * @return void
     */
    public function testHaltsRatherThanSkippingTheItemOnDisconnect(): void
    {
        $source = new ConnectionAwareSource(new ArraySource([1, 2, 3, 4]), static function (): bool {
            static $calls = 0;

            return ++$calls === 2;
        });

        self::assertSame([1], iterator_to_array($source->rows(), false), 'Iteration must halt at the disconnect, not skip the item and continue.');
    }

    /**
     * It never yields a single item when the client is already gone.
     *
     * @return void
     */
    public function testYieldsNothingWhenAbortedImmediately(): void
    {
        $source = new ConnectionAwareSource(new ArraySource([1, 2, 3]), static fn (): bool => true);

        self::assertSame([], iterator_to_array($source->rows(), false));
    }

    /**
     * It forwards eager-load hints to the wrapped source.
     *
     * @return void
     */
    public function testForwardsEagerLoadHints(): void
    {
        $inner  = new ArraySource([1]);
        $source = new ConnectionAwareSource($inner, static fn (): bool => false);

        $source->withRelations(['country', 'orders']);

        self::assertSame(['country', 'orders'], $inner->appliedRelations);
    }

    /**
     * It forwards the aggregate plan to a wrapped aggregate-deriving source.
     *
     * @return void
     */
    public function testForwardsAggregatesToAnAggregateDerivingSource(): void
    {
        $inner  = new AggregateRecordingSource;
        $source = new ConnectionAwareSource($inner, static fn (): bool => false);
        $plan   = new EagerLoadPlan(['orders'], []);

        self::assertSame($source, $source->withAggregates($plan));
        self::assertSame($plan, $inner->appliedPlan);
    }

    /**
     * It is a no-op forwarding aggregates to a source that cannot derive them.
     *
     * @return void
     */
    public function testForwardingAggregatesToANonDerivingSourceIsANoOp(): void
    {
        $source = new ConnectionAwareSource(new ArraySource([1]), static fn (): bool => false);

        self::assertSame($source, $source->withAggregates(new EagerLoadPlan(['orders'], [])));
    }
}
