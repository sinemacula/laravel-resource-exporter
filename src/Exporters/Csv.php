<?php

namespace SineMacula\Exporter\Exporters;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use SineMacula\Exporter\Contracts\Exporter as ExporterContract;

/**
 * The CSV exporter.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
class Csv extends Exporter implements ExporterContract
{
    /** @var array<string, mixed> The default configuration */
    protected const array DEFAULT_CONFIG = [
        'delimiter' => ',',
        'enclosure' => '"'
    ];

    /**
     * Export the given resource item.
     *
     * @param  \Illuminate\Http\Resources\Json\JsonResource  $resource
     * @return string
     */
    public function exportItem(JsonResource $resource): string
    {
        $data = $this->filterData($resource->toArray(Request::instance()));

        return !empty($data)
            ? implode("\n", [
                $this->generateColumns(array_keys($data)),
                $this->generateRow($data) . "\n"
            ])
            : '';
    }

    /**
     * Export the given resource collection.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
     * @return string
     */
    public function exportCollection(ResourceCollection $collection): string
    {
        foreach ($collection as $resource) {

            $data = $this->filterData($resource->toArray(Request::instance()));

            $csv = $csv ?? $this->generateColumns(array_keys($data)) . "\n";

            $csv .= !empty($data)
                ? $this->generateRow($data) . "\n"
                : '';
        }

        return $csv ?? '';
    }

    /**
     * Generate the CSV columns from the keys of the first data array.
     *
     * @param  array  $keys
     * @return string
     */
    protected function generateColumns(array $keys): string
    {
        $columns = array_map(function ($column) {
            return $this->convertToWords($column);
        }, $keys);

        return implode($this->config['delimiter'], array_map([$this, 'escapeValue'], $columns));
    }

    /**
     * Convert a column name to a human-readable string.
     *
     * @param  string  $column
     * @return string
     */
    protected function convertToWords(string $column): string
    {
        return ucwords(str_replace('_', ' ', Str::snake(str_replace('-', ' ', $column))));
    }

    /**
     * Generate a row from the given data array.
     *
     * @param  array  $data
     * @return string
     */
    protected function generateRow(array $data): string
    {
        return implode($this->config['delimiter'], array_map([$this, 'escapeValue'], $data));
    }

    /**
     * Escape a CSV value by wrapping it in quotes and escaping existing quotes.
     *
     * @param  string  $value
     * @return string
     */
    protected function escapeValue(string $value): string
    {
        $enclosure = $this->config['enclosure'];

        return $enclosure . str_replace($enclosure, $enclosure . $enclosure, $value) . $enclosure;
    }
}
