<?php

declare(strict_types = 1);

namespace Tests\Support\Schema;

use Illuminate\Http\Request;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\TabularSchema;

/**
 * Schema exercising the request-aware security boundary.
 *
 * The "secret" column is gated by a request-aware visibility callback (column
 * existence auth), and the "redacted" column delegates its value to a resolver
 * that only surfaces the sensitive field for an authorised request. Together
 * they prove a value the request gates cannot appear in the export.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class GatedSchema extends TabularSchema
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
            Column::make('name', 'Name'),
            Column::make('secret', 'Secret')
                ->visible(static fn (Request $request): bool => (bool) $request->attributes->get('is_admin')),
            Column::make('redacted', 'Redacted')
                ->resolveUsing(static fn (mixed $item, Request $request): mixed => $request->attributes->get('is_admin')
                    ? data_get($item, 'secret')
                    : null),
        ];
    }
}
