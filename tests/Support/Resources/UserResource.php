<?php

declare(strict_types = 1);

namespace Tests\Support\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SineMacula\Exporter\Contracts\ProvidesTabularExport;
use SineMacula\Exporter\Http\Concerns\RespondsWithExports;
use SineMacula\Exporter\Schema\TabularSchema;
use Tests\Support\Schema\UserExportSchema;

/**
 * Negotiable user resource (item and collection) backed by a tabular schema.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class UserResource extends JsonResource implements ProvidesTabularExport
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
        /** @var \Tests\Support\Models\User $user */
        $user = $this->resource;

        return [
            'id'     => $user->id,
            'name'   => $user->name,
            'email'  => $user->email,
            'active' => $user->active,
        ];
    }

    /**
     * Get the tabular schema for the current request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \SineMacula\Exporter\Schema\TabularSchema
     */
    #[\Override]
    public function tabular(Request $request): TabularSchema
    {
        return new UserExportSchema($request);
    }
}
