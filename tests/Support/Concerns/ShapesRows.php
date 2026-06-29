<?php

declare(strict_types = 1);

namespace Tests\Support\Concerns;

use Illuminate\Http\Request;
use SineMacula\Exporter\Schema\CastRegistry;

/**
 * Shapes items into typed-cell rows the way the engine does.
 *
 * Lets a writer test feed a writer the exact map-of-key-to-CellValue rows it
 * receives in production, without coupling the writer test to the engine.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
trait ShapesRows
{
    /**
     * Shape the given items into typed-cell rows for the given columns.
     *
     * @param  list<mixed>  $items
     * @param  list<\SineMacula\Exporter\Schema\Column>  $columns
     * @param  \Illuminate\Http\Request|null  $request
     * @return list<array<string, \SineMacula\Exporter\Schema\CellValue>>
     */
    protected function shapeRows(array $items, array $columns, ?Request $request = null): array
    {
        $request ??= Request::create('/');
        $registry = new CastRegistry;
        $rows     = [];

        foreach ($items as $item) {

            $row = [];

            foreach ($columns as $column) {
                $row[$column->getKey()] = $column->toCellValue($item, $request, $registry);
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
