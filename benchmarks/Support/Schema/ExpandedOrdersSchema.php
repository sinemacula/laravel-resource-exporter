<?php

declare(strict_types = 1);

namespace Benchmarks\Support\Schema;

use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\ExpandPolicy;
use SineMacula\Exporter\Schema\TabularSchema;

/**
 * Parent-to-order fan-out schema for engine row-expansion benchmarks.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ExpandedOrdersSchema extends TabularSchema
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
            Column::make('name', 'Name')->resolveUsing(
                static function (array|object $item): string {
                    $first = data_get($item, 'first_name');
                    $last  = data_get($item, 'last_name');

                    $firstName = is_scalar($first) || $first instanceof \Stringable ? (string) $first : '';
                    $lastName  = is_scalar($last)  || $last instanceof \Stringable ? (string) $last : '';

                    return $firstName . ' ' . $lastName;
                },
            ),
            Column::make('sku', 'SKU')->expandRows(),
            Column::make('quantity', 'Quantity')->expandRows()->number(),
            Column::make('total', 'Total')->expandRows()->number(2),
            Column::make('purchased_at', 'Purchased')->expandRows()->date('Y-m-d'),
        ];
    }

    /**
     * Get the row-expansion policy.
     *
     * @return \SineMacula\Exporter\Schema\ExpandPolicy
     */
    #[\Override]
    public function expand(): ExpandPolicy
    {
        return new ExpandPolicy('orders');
    }
}
