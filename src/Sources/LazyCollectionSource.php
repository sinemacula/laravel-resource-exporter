<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Sources;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;
use SineMacula\Exporter\Contracts\Source;

/**
 * Lazy collection source adapter.
 *
 * Normalises a LazyCollection into a lazy stream of its underlying domain
 * items, preserving constant memory. When eager-load hints are present the
 * stream is chunked and the requested relations are loaded per chunk so the
 * whole set is never resident at once.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class LazyCollectionSource implements Source
{
    /** @var list<string> The eager-load relations to apply */
    private array $with = [];

    /**
     * Constructor.
     *
     * @param  \Illuminate\Support\LazyCollection<array-key, mixed>  $items
     * @param  int  $chunkSize
     * @return void
     */
    public function __construct(

        /** @var \Illuminate\Support\LazyCollection<array-key, mixed> The lazy item stream */
        private readonly LazyCollection $items,

        /** The chunk size used when eager-loading relations. */
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
        if ($this->with === []) {
            foreach ($this->items as $item) {
                yield $item;
            }

            return;
        }

        foreach ($this->items->chunk($this->chunkSize, false) as $chunk) {

            $this->loadRelations($chunk);

            foreach ($chunk as $item) {
                yield $item;
            }
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
     * Eager-load the requested relations across the models in a chunk.
     *
     * @param  iterable<array-key, mixed>  $chunk
     * @return void
     */
    private function loadRelations(iterable $chunk): void
    {
        $models = [];

        foreach ($chunk as $item) {

            if (!$item instanceof Model) {
                continue;
            }

            $models[] = $item;
        }

        if ($models === []) {
            return;
        }

        EloquentCollection::make($models)->loadMissing($this->with);
    }
}
