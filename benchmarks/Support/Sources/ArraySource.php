<?php

declare(strict_types = 1);

namespace Benchmarks\Support\Sources;

use SineMacula\Exporter\Contracts\Source;

/**
 * Lightweight in-memory source for engine benchmarks.
 *
 * @phpstan-type SourceRow array<string, bool|float|int|string|null|list<array<string, float|int|string>>>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ArraySource implements Source
{
    /**
     * Create the source.
     *
     * @param  list<SourceRow>  $items
     */
    public function __construct(

        /** @var list<SourceRow> The items yielded by the source */
        private array $items,
    ) {}

    /**
     * Iterate the source as domain items.
     *
     * @return iterable<int, SourceRow>
     */
    #[\Override]
    public function rows(): iterable
    {
        yield from $this->items;
    }

    /**
     * Apply eager-load hints.
     *
     * @param  list<string>  $with
     * @return static
     */
    #[\Override]
    public function withRelations(array $with): static
    {
        return $this;
    }
}
