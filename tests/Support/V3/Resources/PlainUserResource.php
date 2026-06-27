<?php

declare(strict_types = 1);

namespace Tests\Support\V3\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SineMacula\Exporter\Http\Concerns\RespondsWithExports;

/**
 * Negotiable resource with no tabular schema (drives the 406 path).
 *
 * It uses the export trait - so its collection becomes an
 * ExportResourceCollection - but does not implement ProvidesTabularExport, so a
 * tabular format must yield a 406 rather than a silent JSON fallback.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class PlainUserResource extends JsonResource
{
    use RespondsWithExports;

    /**
     * Transform the resource into its JSON representation.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var \Tests\Support\V3\Models\User $user */
        $user = $this->resource;

        return [
            'id'   => $user->id,
            'name' => $user->name,
        ];
    }
}
