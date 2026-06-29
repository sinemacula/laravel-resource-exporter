<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Sources;

use Illuminate\Database\Eloquent\Builder;
use SineMacula\Exporter\Contracts\DerivesAggregates;
use SineMacula\Exporter\Contracts\Source;
use SineMacula\Exporter\Schema\EagerLoadPlan;

/**
 * Query chunk source adapter.
 *
 * Normalises an Eloquent query into a keyset-paginated, lazy stream of its
 * models at constant memory. It uses lazyById() (stable under concurrent
 * inserts and self-ordering, unlike lazy()/cursor()) and force-selects the key
 * column so a constrained select() cannot abort the stream mid-flight.
 * Requested relations are applied to the query so each chunk eager-loads them,
 * and the schema's derived aggregates are applied as withCount()/withSum() so a
 * count or sum column carries its value without the caller wiring it by hand.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class QueryChunkSource implements DerivesAggregates, Source
{
    /** @var list<string> The eager-load relations to apply */
    private array $with = [];

    /** @var \SineMacula\Exporter\Schema\EagerLoadPlan|null The derived aggregate plan to apply */
    private ?EagerLoadPlan $aggregates = null;

    /**
     * Constructor.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  int  $chunkSize
     * @return void
     */
    public function __construct(

        /** @var \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> The query streamed in keyset chunks */
        private readonly Builder $query,

        /** The number of records fetched per keyset chunk. */
        private readonly int $chunkSize = 1000,
    ) {}

    /**
     * Iterate the source as a lazy stream of domain items.
     *
     * @return iterable<int, mixed>
     */
    #[\Override]
    public function rows(): iterable
    {
        $direction = $this->keysetDirection();

        $this->forceSelectKey();

        if ($this->with !== []) {
            $this->query->with($this->with);
        }

        $this->applyAggregates();

        $rows = $direction === 'desc'
            ? $this->query->lazyByIdDesc($this->chunkSize)
            : $this->query->lazyById($this->chunkSize);

        foreach ($rows as $item) {
            yield $item;
        }
    }

    /**
     * Assert that the query can be streamed safely before bytes are committed.
     *
     * @return void
     *
     * @throws \LogicException
     */
    public function guardKeysetOrdering(): void
    {
        $this->keysetDirection();
    }

    /**
     * Apply the eager-load hints the schema requested.
     *
     * @param  list<string>  $with
     * @return static
     */
    #[\Override]
    public function withRelations(array $with): static
    {
        $this->with = $with;

        return $this;
    }

    /**
     * Apply the derived aggregate eager-load plan to the source.
     *
     * @param  \SineMacula\Exporter\Schema\EagerLoadPlan  $plan
     * @return static
     */
    #[\Override]
    public function withAggregates(EagerLoadPlan $plan): static
    {
        $this->aggregates = $plan;

        return $this;
    }

    /**
     * Derive the count and sum aggregates onto the query.
     *
     * withCount()/withSum() name their alias columns ({relation}_count and
     * {relation}_sum_{column}) exactly as the column reads them back, so each
     * model carries the aggregate by the time it is shaped.
     *
     * @return void
     */
    private function applyAggregates(): void
    {
        if ($this->aggregates === null) {
            return;
        }

        if ($this->aggregates->count !== []) {
            $this->query->withCount($this->aggregates->count);
        }

        foreach ($this->aggregates->sum as $sum) {
            $this->query->withSum($sum['relation'], $sum['column']);
        }
    }

    /**
     * Ensure the key column is part of a constrained select.
     *
     * lazyById() compares IDs across chunks and throws if the key column is
     * absent from the hydrated results. A bare query selects everything, so the
     * key is only at risk when an explicit select() narrows the columns.
     *
     * @return void
     */
    private function forceSelectKey(): void
    {
        $columns = $this->query->getQuery()->columns;

        if ($columns === null || $columns === []) {
            return;
        }

        $key       = $this->query->getModel()->getKeyName();
        $qualified = $this->query->qualifyColumn($key);

        foreach ($columns as $column) {
            if (!is_string($column)) {
                continue;
            }

            if ($column === '*' || $column === $key || $column === $qualified || str_ends_with($column, '.*')) {
                return;
            }
        }

        $this->query->getQuery()->addSelect($qualified);
    }

    /**
     * Resolve whether the query can be streamed safely by primary key order.
     *
     * Eloquent's lazyById() appends a keyset predicate but does not make a
     * non-key ORDER BY stable across chunks. Rejecting those orders is safer
     * than silently duplicating or skipping rows in a full export.
     *
     * @return 'asc'|'desc'
     *
     * @throws \LogicException
     */
    private function keysetDirection(): string
    {
        $orders = $this->keysetOrders();

        if ($orders === []) {
            return 'asc';
        }

        $key        = $this->query->getModel()->getKeyName();
        $qualified  = $this->query->qualifyColumn($key);
        $directions = [];

        foreach ($orders as $order) {
            $this->guardKeysetOrder($order, $key, $qualified);
            $directions[] = $this->orderDirection($order);
        }

        $unique = array_values(array_unique($directions));

        if (count($unique) > 1) {
            throw new \LogicException("QueryChunkSource cannot stream conflicting keyset directions for [{$key}].");
        }

        return $unique[0];
    }

    /**
     * Read the query's configured order clauses.
     *
     * @return list<array{column: object|string|null, direction: string}>
     */
    private function keysetOrders(): array
    {
        $orders = array_merge(
            $this->query->getQuery()->orders      ?? [],
            $this->query->getQuery()->unionOrders ?? [],
        );

        $normalised = [];

        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }

            $column    = $order['column']    ?? null;
            $direction = $order['direction'] ?? 'asc';

            $normalised[] = [
                'column'    => is_string($column) || is_object($column) ? $column : null,
                'direction' => is_string($direction) ? $direction : 'asc',
            ];
        }

        return $normalised;
    }

    /**
     * Guard that an order clause sorts only by the model key.
     *
     * @param  array{column: object|string|null, direction: string}  $order
     * @param  string  $key
     * @param  string  $qualified
     * @return void
     *
     * @throws \LogicException
     */
    private function guardKeysetOrder(array $order, string $key, string $qualified): void
    {
        $column = $order['column'] ?? null;

        if ($column === $key || $column === $qualified) {
            return;
        }

        $label = is_string($column) ? $column : 'raw expression';

        throw new \LogicException("QueryChunkSource can only stream keyset-safe ordering by [{$key}]; [{$label}] would duplicate or skip rows across chunks.");
    }

    /**
     * Resolve the normalised direction for an order clause.
     *
     * @param  array{column: object|string|null, direction: string}  $order
     * @return 'asc'|'desc'
     */
    private function orderDirection(array $order): string
    {
        return strtolower($order['direction']) === 'desc'
            ? 'desc'
            : 'asc';
    }
}
