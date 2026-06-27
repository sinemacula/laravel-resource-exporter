<?php

declare(strict_types = 1);

namespace Tests\Support\V3\Schema;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\TabularSchema;

/**
 * Schema that surfaces the request's resolved user on every row.
 *
 * The "actor" column reads the authenticated identifier off the request the
 * queued job builds, so an export only carries it when the job has bound the
 * re-resolved actor onto that request via setUserResolver(). It proves the
 * worker threads the initiating actor through to the schema, not just the gate.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class ActorAwareSchema extends TabularSchema
{
    /**
     * Get the ordered columns for the export.
     *
     * @return list<\SineMacula\Exporter\Schema\Column>
     */
    #[\Override]
    public function columns(): array
    {
        return [
            Column::make('id', 'ID'),
            Column::make('actor', 'Actor')->resolveUsing(
                static function (mixed $item, Request $request): mixed {
                    $user = $request->user();

                    return $user instanceof Authenticatable ? $user->getAuthIdentifier() : null;
                },
            ),
        ];
    }
}
