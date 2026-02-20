<?php

declare(strict_types = 1);

namespace Tests\Support\Exporters;

use SineMacula\Exporter\Exporters\Csv;

/**
 * CSV harness exposing protected methods for direct assertions.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class CsvTestHarness extends Csv
{
    /**
     * Expose protected column generation.
     *
     * @param  array<int, string>  $keys
     * @return string
     */
    public function exposeGenerateColumns(array $keys): string
    {
        return $this->generateColumns($keys);
    }

    /**
     * Expose protected column normalization.
     *
     * @param  string  $column
     * @return string
     */
    public function exposeConvertToWords(string $column): string
    {
        return $this->convertToWords($column);
    }

    /**
     * Expose protected row generation.
     *
     * @param  array<int|string, scalar|null>  $data
     * @return string
     */
    public function exposeGenerateRow(array $data): string
    {
        return $this->generateRow($data);
    }

    /**
     * Expose protected value escaping.
     *
     * @param  bool|float|int|string|null  $value
     * @return string
     */
    public function exposeEscapeValue(bool|float|int|string|null $value): string
    {
        return $this->escapeValue($value);
    }

    /**
     * Expose protected data filtering.
     *
     * @param  array<int|string, mixed>  $data
     * @return array<string, scalar|null>
     */
    public function exposeFilterData(array $data): array
    {
        return $this->filterData($data);
    }
}
