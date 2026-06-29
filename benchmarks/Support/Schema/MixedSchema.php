<?php

declare(strict_types = 1);

namespace Benchmarks\Support\Schema;

use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\TabularSchema;

/**
 * Mixed scalar schema used by engine and writer benchmarks.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class MixedSchema extends TabularSchema
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
            Column::make('first_name', 'First Name'),
            Column::make('last_name', 'Last Name')->formatUsing(
                static fn (mixed $value): string => is_string($value) ? strtoupper($value) : '',
            ),
            Column::make('email', 'Email')->visible(static fn (): bool => true),
            Column::make('is_active', 'Active')->boolean('Yes', 'No'),
            Column::make('balance', 'Balance')->number(2),
            Column::make('reference', 'Reference')->default('n/a'),
            Column::make('created_at', 'Created')->date('Y-m-d'),
            Column::make('display_name', 'Display')->resolveUsing(
                static function (array|object $item): string {
                    $first = data_get($item, 'first_name');
                    $last  = data_get($item, 'last_name');

                    $firstName = is_scalar($first) || $first instanceof \Stringable ? (string) $first : '';
                    $lastName  = is_scalar($last)  || $last instanceof \Stringable ? (string) $last : '';

                    return $firstName . ' ' . $lastName;
                },
            ),
        ];
    }
}
