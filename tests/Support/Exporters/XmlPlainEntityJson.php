<?php

declare(strict_types = 1);

namespace Tests\Support\Exporters;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plain-entity JsonResource for collection naming tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class XmlPlainEntityJson extends JsonResource
{
    /**
     * Convert the resource payload to array.
     *
     * @param  mixed  $request
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(mixed $request): array
    {
        $payload = [];

        foreach ((array) $this->resource as $key => $value) {
            $payload[(string) $key] = $value;
        }

        return $payload;
    }
}
