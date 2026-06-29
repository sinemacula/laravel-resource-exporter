<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Contracts;

use SineMacula\Exporter\Schema\EagerLoadPlan;

/**
 * Aggregate-deriving source capability.
 *
 * An optional capability a Source advertises when it can derive database
 * aggregates onto its items - a query applies withCount()/withSum() before it
 * streams, and an Eloquent-backed collection loadCount()/loadSum()s its models.
 * The engine inspects the schema's columns, builds an EagerLoadPlan, and
 * applies it only to sources that implement this, so a count or sum column
 * carries its value end to end without the caller wiring the eager-load by
 * hand. Sources that wrap already-materialised data simply do not implement it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface DerivesAggregates
{
    /**
     * Apply the derived aggregate eager-load plan to the source.
     *
     * @param  \SineMacula\Exporter\Schema\EagerLoadPlan  $plan
     * @return static
     */
    public function withAggregates(EagerLoadPlan $plan): static;
}
