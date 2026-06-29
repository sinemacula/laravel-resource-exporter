<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Sources;

use SineMacula\Exporter\Contracts\DerivesAggregates;
use SineMacula\Exporter\Contracts\Source;
use SineMacula\Exporter\Schema\EagerLoadPlan;

/**
 * Connection-aware source decorator.
 *
 * Wraps another source and stops iterating early when the client disconnects.
 * Before yielding each item it consults an abort signal - by default
 * connection_aborted(), overridable for testing - and returns the moment it
 * reports the connection has gone, so an expensive keyset query is abandoned
 * rather than streamed to a browser that is no longer listening. A clean early
 * stop is not a failure: the wrapped writer simply finishes its current
 * structure with the rows it already has.
 *
 * The decorator is transparent to the engine: it forwards eager-load hints and,
 * when the wrapped source can derive aggregates, the aggregate plan too, so the
 * streamed query still loads its relations and counts. It only ever applies to
 * the HTTP streaming path, where connection_aborted() is meaningful.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ConnectionAwareSource implements DerivesAggregates, Source
{
    /**
     * Create a new connection-aware source.
     *
     * @param  \SineMacula\Exporter\Contracts\Source  $source
     * @param  \Closure(): bool  $aborted
     */
    public function __construct(

        /** The wrapped source the rows are streamed from. */
        private Source $source,

        /** The abort signal reporting whether the client has disconnected. */
        private readonly \Closure $aborted,
    ) {}

    /**
     * Iterate the wrapped source, stopping the moment the client disconnects.
     *
     * @return iterable<int, mixed>
     */
    #[\Override]
    public function rows(): iterable
    {
        foreach ($this->source->rows() as $item) {

            if (($this->aborted)()) {
                break;
            }

            yield $item;
        }
    }

    /**
     * Forward the eager-load hints to the wrapped source.
     *
     * @param  list<string>  $with
     * @return static
     */
    #[\Override]
    public function withRelations(array $with): static
    {
        $this->source = $this->source->withRelations($with);

        return $this;
    }

    /**
     * Forward the derived aggregate plan to the wrapped source when it supports
     * one.
     *
     * @param  \SineMacula\Exporter\Schema\EagerLoadPlan  $plan
     * @return static
     */
    #[\Override]
    public function withAggregates(EagerLoadPlan $plan): static
    {
        if ($this->source instanceof DerivesAggregates) {
            $this->source = $this->source->withAggregates($plan);
        }

        return $this;
    }
}
