<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Export;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SineMacula\Exporter\Contracts\Source;

/**
 * Hierarchical row resolver.
 *
 * Lazily resolves each domain item streamed by a Source into the hierarchical
 * array shape the JSON, NDJSON and XML writers consume, applying the resource's
 * request-aware accessors. An item that is already a resource resolves itself;
 * a raw domain item is wrapped in the configured resource class first, so the
 * fluent builder can drive a hierarchical export from a query or a collection.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class HierarchicalRows
{
    /**
     * Create a new hierarchical row resolver.
     *
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>|null  $resourceClass
     */
    public function __construct(

        /** @var class-string<\Illuminate\Http\Resources\Json\JsonResource>|null The resource class wrapping raw items */
        private ?string $resourceClass,
    ) {}

    /**
     * Resolve each source item to its hierarchical array, counting as it goes.
     *
     * @param  \SineMacula\Exporter\Contracts\Source  $source
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(): void  $count
     * @return \Generator<int, array<array-key, mixed>>
     */
    public function resolve(Source $source, Request $request, \Closure $count): \Generator
    {
        foreach ($source->rows() as $item) {
            $count();

            yield $this->resolveItem($item, $request);
        }
    }

    /**
     * Resolve a single source item to its hierarchical array via the resource.
     *
     * @param  mixed  $item
     * @param  \Illuminate\Http\Request  $request
     * @return array<array-key, mixed>
     *
     * @throws \LogicException
     */
    private function resolveItem(mixed $item, Request $request): array
    {
        if ($item instanceof JsonResource) {
            return $item->resolve($request);
        }

        if ($this->resourceClass === null) {
            throw new \LogicException('A resource class is required to export this subject as a hierarchical format.');
        }

        $class = $this->resourceClass;

        return (new $class($item))->resolve($request);
    }
}
