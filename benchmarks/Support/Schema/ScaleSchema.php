<?php

declare(strict_types = 1);

namespace Benchmarks\Support\Schema;

use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\TabularSchema;

/**
 * Fixed three-column schema for the streaming-scale benchmarks.
 *
 * Provides the headings and column order the scale benchmark drives synthetic
 * generator rows through, so the benchmark exercises the real CSV heading and
 * per-cell rendering path without a resource or a database.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ScaleSchema extends TabularSchema
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
            Column::make('score', 'Score')->number(2),
        ];
    }
}
