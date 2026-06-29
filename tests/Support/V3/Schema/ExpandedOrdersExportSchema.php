<?php

declare(strict_types = 1);

namespace Tests\Support\V3\Schema;

use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\ExpandPolicy;
use SineMacula\Exporter\Schema\TabularSchema;

/**
 * Export schema that expands users across their orders relation.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class ExpandedOrdersExportSchema extends TabularSchema
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
            Column::make('sku', 'SKU')->expandRows(),
            Column::make('total', 'Total')->expandRows()->number(0),
        ];
    }

    /**
     * Get the single row-expansion relation.
     *
     * @return \SineMacula\Exporter\Schema\ExpandPolicy
     */
    #[\Override]
    public function expand(): ExpandPolicy
    {
        return new ExpandPolicy('orders');
    }
}
