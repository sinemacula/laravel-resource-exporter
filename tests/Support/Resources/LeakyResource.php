<?php

declare(strict_types = 1);

namespace Tests\Support\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource whose serialisation throws.
 *
 * Used to prove a source streams the underlying items without ever routing them
 * through toArray()/resolve(): if the source serialised, this throw would trip.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class LeakyResource extends JsonResource
{
    /**
     * Throw if the resource is ever serialised.
     *
     * @param  mixed  $request
     * @return never
     *
     * @throws \LogicException
     */
    #[\Override]
    public function toArray(mixed $request)
    {
        throw new \LogicException('The source must not serialise resources.');
    }
}
