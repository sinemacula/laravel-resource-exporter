<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Sources;

use Illuminate\Database\Eloquent\Builder;
use SineMacula\Exporter\Contracts\Source;

/**
 * Query chunk source adapter.
 *
 * Normalises an Eloquent query into a keyset-paginated, lazy stream of its
 * models at constant memory. It uses lazyById() (stable under concurrent
 * inserts and self-ordering, unlike lazy()/cursor()) and force-selects the
 * key column so a constrained select() cannot abort the stream mid-flight.
 * Requested relations are applied to the query so each chunk eager-loads them.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class QueryChunkSource implements Source
{
    /** @var list<string> The eager-load relations to apply */
    private array $with = [];

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
        $this->forceSelectKey();

        if ($this->with !== []) {
            $this->query->with($this->with);
        }

        foreach ($this->query->lazyById($this->chunkSize) as $item) {
            yield $item;
        }
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
}
