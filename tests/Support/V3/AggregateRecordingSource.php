<?php

declare(strict_types = 1);

namespace Tests\Support\V3;

use SineMacula\Exporter\Contracts\DerivesAggregates;
use SineMacula\Exporter\Contracts\Source;
use SineMacula\Exporter\Schema\EagerLoadPlan;

/**
 * In-memory aggregate-deriving source for decorator tests.
 *
 * Yields a fixed list and records the eager-load hints and the aggregate plan
 * it is handed, so a test can assert a decorating source forwards both onto the
 * source it wraps without a database.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class AggregateRecordingSource implements DerivesAggregates, Source
{
    /** @var list<string> The relations forwarded to the source */
    public array $appliedRelations = [];

    /** @var \SineMacula\Exporter\Schema\EagerLoadPlan|null The aggregate plan forwarded to the source */
    public ?EagerLoadPlan $appliedPlan = null;

    /**
     * Iterate the source as a lazy stream of domain items.
     *
     * @return iterable<int, mixed>
     */
    #[\Override]
    public function rows(): iterable
    {
        yield from [];
    }

    /**
     * Record the eager-load hints the engine forwarded.
     *
     * @param  list<string>  $with
     * @return static
     */
    #[\Override]
    public function withRelations(array $with): static
    {
        $this->appliedRelations = $with;

        return $this;
    }

    /**
     * Record the derived aggregate plan the engine forwarded.
     *
     * @param  \SineMacula\Exporter\Schema\EagerLoadPlan  $plan
     * @return static
     */
    #[\Override]
    public function withAggregates(EagerLoadPlan $plan): static
    {
        $this->appliedPlan = $plan;

        return $this;
    }
}
