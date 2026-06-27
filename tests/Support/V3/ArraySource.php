<?php

declare(strict_types = 1);

namespace Tests\Support\V3;

use SineMacula\Exporter\Contracts\Source;

/**
 * In-memory source adapter for engine and writer tests.
 *
 * Yields a fixed list of items lazily and records the relations the engine
 * requested, so a test can drive the export pipeline without a database while
 * still asserting the schema's eager-load hints were forwarded.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class ArraySource implements Source
{
    /** @var list<string> The relations the engine forwarded */
    public array $appliedRelations = [];

    /**
     * Create a new in-memory source.
     *
     * @param  list<mixed>  $items
     */
    public function __construct(

        /** @var list<mixed> The fixed items to yield */
        private readonly array $items,
    ) {}

    /**
     * Iterate the source as a lazy stream of domain items.
     *
     * @return iterable<int, mixed>
     */
    #[\Override]
    public function rows(): iterable
    {
        yield from $this->items;
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
        $this->appliedRelations = $with;

        return $this;
    }
}
