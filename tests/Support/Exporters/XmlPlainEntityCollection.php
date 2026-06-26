<?php

declare(strict_types = 1);

namespace Tests\Support\Exporters;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * ResourceCollection where collects does not end in "Resource".
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class XmlPlainEntityCollection extends ResourceCollection
{
    /** @var string */
    public $collects = XmlPlainEntityJson::class;

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
