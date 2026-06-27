<?php

declare(strict_types = 1);

namespace Tests\Support\V3\Schema;

use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\TabularSchema;

/**
 * Tabular schema for the user resource used across negotiation tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class UserExportSchema extends TabularSchema
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
            Column::make('email', 'Email'),
            Column::make('active', 'Active')->boolean('Yes', 'No'),
            Column::make('created_at', 'Joined')->date('Y-m-d'),
        ];
    }

    /**
     * Get the filename hint for downloads, without extension.
     *
     * @return string
     */
    #[\Override]
    public function filename(): string
    {
        return 'users';
    }
}
