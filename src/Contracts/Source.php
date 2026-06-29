<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Contracts;

/**
 * Source adapter contract.
 *
 * Normalises an input (resource, collection, paginator page, lazy collection,
 * or query) into a uniform, lazily-iterated stream of domain items. Adapters
 * must never buffer the whole set - one item per step, at constant memory.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface Source
{
    /**
     * Iterate the source as a lazy stream of domain items.
     *
     * Yields one domain item (model, array, or resource payload) per step so
     * the writer can shape and emit a row without materialising the set.
     *
     * @return iterable<int, mixed>
     */
    public function rows(): iterable;

    /**
     * Apply the eager-load hints the schema requested.
     *
     * @param  list<string>  $with
     * @return static
     */
    public function withRelations(array $with): static;
}
