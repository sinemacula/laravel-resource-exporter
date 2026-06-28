<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Writers;

use SineMacula\Exporter\Schema\CellValue;

/**
 * Shared cell value coercion.
 *
 * The value-coercion semantics every tabular writer shares: a typed CellValue
 * is reduced to a native integer, float, boolean label, or string the same way
 * regardless of the target format, so the CSV/TSV text output and the typed
 * XLSX spreadsheet agree on what each cell holds. The writer keeps ownership of
 * the target type (a string field, a native numeric cell, a date cell); this
 * collaborator only decides the coerced value. It holds no state and is safe to
 * share under Octane.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class CellRenderer
{
    /**
     * Render an integer cell to a native integer.
     *
     * @param  mixed  $raw
     * @return int
     */
    public function renderInt(mixed $raw): int
    {
        if (is_int($raw)) {
            return $raw;
        }

        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * Render a float cell to a native float.
     *
     * @param  mixed  $raw
     * @return float
     */
    public function renderFloat(mixed $raw): float
    {
        if (is_float($raw)) {
            return $raw;
        }

        return is_numeric($raw) ? (float) $raw : 0.0;
    }

    /**
     * Render a boolean cell to its configured label.
     *
     * @param  \SineMacula\Exporter\Schema\CellValue  $cell
     * @return string
     */
    public function renderBoolean(CellValue $cell): string
    {
        $labels = $cell->format !== null && str_contains($cell->format, '|')
            ? explode('|', $cell->format, 2)
            : ['Yes', 'No'];

        return $cell->raw ? $labels[0] : ($labels[1] ?? 'No');
    }

    /**
     * Render an arbitrary value to its string representation.
     *
     * @param  mixed  $raw
     * @return string
     */
    public function renderString(mixed $raw): string
    {
        if (is_string($raw)) {
            return $raw;
        }

        if (is_scalar($raw) || $raw instanceof \Stringable) {
            return (string) $raw;
        }

        return '';
    }
}
