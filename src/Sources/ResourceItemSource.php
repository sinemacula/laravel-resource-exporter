<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Sources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use SineMacula\Exporter\Contracts\Source;

/**
 * Single resource source adapter.
 *
 * Normalises one JsonResource into a single-step stream of its underlying
 * domain item. The resource is never serialised through toArray()/resolve();
 * the raw underlying model (or value) is yielded and the schema reads its raw
 * attributes directly. Field visibility from the resource's toArray()/$hidden/
 * when() gating is therefore NOT inherited; gate a column out of the export
 * with the schema's ->visible().
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ResourceItemSource implements Source
{
    /** @var list<string> The eager-load relations to apply */
    private array $with = [];

    /**
     * Constructor.
     *
     * @param  \Illuminate\Http\Resources\Json\JsonResource  $resource
     * @return void
     */
    public function __construct(

        /** The resource whose underlying item is exported. */
        private readonly JsonResource $resource,
    ) {}

    /**
     * Iterate the source as a lazy stream of domain items.
     *
     * @return iterable<int, mixed>
     */
    #[\Override]
    public function rows(): iterable
    {
        $item = $this->resource->resource;

        if ($this->with !== [] && $item instanceof Model) {
            $item->loadMissing($this->with);
        }

        yield $item;
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
