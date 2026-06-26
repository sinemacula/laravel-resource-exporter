<?php

declare(strict_types = 1);

namespace Tests\Support\Exporters;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * ResourceCollection for XML tests with a collects suffix.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class XmlUserResourceCollection extends ResourceCollection
{
    /** @var string */
    public $collects = XmlUserResource::class;

    /**
     * Convert the collection payload to array.
     *
     * @param  mixed  $request
     * @return array<int, array<string, mixed>>
     */
    #[\Override]
    public function toArray(mixed $request): array
    {
        $collection = $this->collection ?? collect();

        return $collection->map(
            static fn (mixed $item): array => $item instanceof JsonResource
                ? $item->resolve()
                : (array) $item,
        )->all();
    }
}
