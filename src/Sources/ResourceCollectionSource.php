<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Sources;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use SineMacula\Exporter\Contracts\Source;

/**
 * Resource collection source adapter.
 *
 * Normalises a ResourceCollection into a lazy stream of its underlying domain
 * items. It iterates the already-mapped, in-memory collection and unwraps each
 * resource to its underlying model, never routing rows through the collection's
 * toArray()/resolve()/all() (which would serialise and buffer the whole set).
 * The schema reads the raw model attributes directly, so the resource's
 * toArray()/$hidden/when() field gating is NOT inherited; gate a column out of
 * the export with the schema's ->visible().
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ResourceCollectionSource implements Source
{
    /** @var list<string> The eager-load relations to apply */
    private array $with = [];

    /**
     * Constructor.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
     * @return void
     */
    public function __construct(

        /** The resource collection whose underlying items are exported. */
        private readonly ResourceCollection $collection,
    ) {}

    /**
     * Iterate the source as a lazy stream of domain items.
     *
     * @return iterable<int, mixed>
     */
    #[\Override]
    public function rows(): iterable
    {
        $items  = [];
        $models = [];

        foreach ($this->collection->collection ?? [] as $entry) {

            $item    = $entry instanceof JsonResource ? $entry->resource : $entry;
            $items[] = $item;

            if (!$item instanceof Model) {
                continue;
            }

            $models[] = $item;
        }

        if ($this->with !== [] && $models !== []) {
            EloquentCollection::make($models)->loadMissing($this->with);
        }

        foreach ($items as $item) {
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
}
