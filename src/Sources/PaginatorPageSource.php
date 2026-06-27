<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Sources;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use SineMacula\Exporter\Contracts\Source;

/**
 * Paginator page source adapter.
 *
 * Normalises a single paginator page into a lazy stream of its underlying
 * domain items. It reads the page's already-fetched items() directly and never
 * wraps them in a resource collection, so the page is exported without any
 * additional serialisation pass.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PaginatorPageSource implements Source
{
    /** @var list<string> The eager-load relations to apply */
    private array $with = [];

    /**
     * Constructor.
     *
     * @param  \Illuminate\Contracts\Pagination\Paginator<int, object>  $paginator
     * @return void
     */
    public function __construct(

        /** @var \Illuminate\Contracts\Pagination\Paginator<int, object> The paginator page being exported */
        private readonly Paginator $paginator,
    ) {}

    /**
     * Iterate the source as a lazy stream of domain items.
     *
     * @return iterable<int, mixed>
     */
    #[\Override]
    public function rows(): iterable
    {
        $items  = $this->paginator->items();
        $models = [];

        foreach ($items as $item) {

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
