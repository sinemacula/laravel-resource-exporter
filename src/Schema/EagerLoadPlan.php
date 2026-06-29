<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Schema;

/**
 * Aggregate eager-load plan.
 *
 * The derived, source-agnostic description of the database aggregates a
 * schema's columns need: the relations to count and the relation/column pairs
 * to sum. A source that derives aggregates (a query, or an Eloquent-backed
 * collection) reads this to apply withCount()/withSum() so each item carries
 * the aggregate the column then folds into a cell. Plain join() aggregates are
 * ordinary eager-loads and travel through withRelations(), not this plan.
 * Immutable and stateless.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class EagerLoadPlan
{
    /**
     * Create a new aggregate eager-load plan.
     *
     * @param  list<string>  $count
     * @param  list<array{relation: string, column: string}>  $sum
     */
    public function __construct(

        /** @var list<string> The relations to load a count for. */
        public array $count = [],

        /** @var list<array{relation: string, column: string}> The relation/column pairs to load a sum for. */
        public array $sum = [],
    ) {}

    /**
     * Determine whether the plan derives no aggregates.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->count === [] && $this->sum === [];
    }
}
