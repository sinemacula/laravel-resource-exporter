<?php

declare(strict_types = 1);

namespace Tests\Support\V3\Schema;

use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\Enums\Strictness;
use SineMacula\Exporter\Schema\TabularSchema;

/**
 * Lenient queued-export schema with one intentionally invalid column.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class LenientBrokenColumnSchema extends TabularSchema
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
            Column::make('broken', 'Broken')->cast('does-not-exist'),
        ];
    }

    /**
     * Get the row-level strictness mode.
     *
     * @return \SineMacula\Exporter\Schema\Enums\Strictness
     */
    #[\Override]
    public function strictness(): Strictness
    {
        return Strictness::LENIENT;
    }
}
