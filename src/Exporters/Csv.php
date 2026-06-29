<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Exporters;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Str;
use SineMacula\Exporter\Contracts\Exporter as ExporterContract;

/**
 * The CSV exporter.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class Csv extends Exporter implements ExporterContract
{
    /** @var array<string, mixed> The default configuration */
    protected const array DEFAULT_CONFIG = [
        'delimiter' => ',',
        'enclosure' => '"',
    ];

    /** @var bool Whether to include headers in the CSV file */
    protected bool $includeHeaders = true;

    /**
     * Do not include headers in the CSV file.
     *
     * @return $this
     */
    public function withoutHeaders(): self
    {
        $this->includeHeaders = false;

        return $this;
    }

    /**
     * Export a raw array of associative arrays.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return string
     */
    #[\Override]
    public function exportArray(array $rows): string
    {
        return $this->exportRows($rows);
    }

    /**
     * Export the given resource item.
     *
     * @param  \Illuminate\Http\Resources\Json\JsonResource  $resource
     * @return string
     */
    #[\Override]
    public function exportItem(JsonResource $resource): string
    {
        return $this->exportRows([$resource->resolve()]);
    }

    /**
     * Export the given resource collection.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
     * @return string
     */
    #[\Override]
    public function exportCollection(ResourceCollection $collection): string
    {
        return $this->exportRows($collection->resolve());
    }

    /**
     * Generate the CSV columns from the keys of the first data array.
     *
     * @param  array<int, string>  $keys
     * @return string
     */
    protected function generateColumns(array $keys): string
    {
        if (!$this->includeHeaders) {
            return '';
        }

        $columns = array_map(fn ($column) => $this->convertToWords($column), $keys);

        return implode($this->getDelimiter(), array_map([$this, 'escapeValue'], $columns));
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
     * @param  array<int|string, scalar|null>  $data
     * @return string
     */
    protected function generateRow(array $data): string
    {
        return implode($this->getDelimiter(), array_map([$this, 'escapeValue'], $data));
    }

    /**
     * Escape a CSV value by wrapping it in quotes and escaping existing quotes.
     *
     * @param  bool|float|int|string|null  $value
     * @return string
     */
    protected function escapeValue(bool|float|int|string|null $value): string
    {
        $enclosure = $this->getEnclosure();

        if (is_string($value)) {
            $string = $this->neutraliseFormula($value);
        } elseif ($value === null) {
            $string = '';
        } else {
            $string = (string) $value;
        }

        return $enclosure . str_replace($enclosure, $enclosure . $enclosure, $string) . $enclosure;
    }

    /**
     * Filter the data array to exclude non-stringable values and ignored
     * fields.
     *
     * @param  array<int|string, mixed>  $data
     * @return array<string, scalar|null>
     */
    protected function filterData(array $data): array
    {
        $filtered = [];

        foreach ($data as $key => $value) {

            $field = is_int($key)
                ? (string) $key
                : $key;

            if (in_array($field, $this->ignored, true)) {
                continue;
            }

            if (is_scalar($value) || is_null($value)) {
                $filtered[$field] = $value;
                continue;
            }

            if (!$value instanceof \Stringable) {
                continue;
            }

            $filtered[$field] = (string) $value;
        }

        return $filtered;
    }

    /**
     * Neutralise a spreadsheet formula trigger at the start of a text value.
     *
     * A field a spreadsheet would evaluate as a formula - one beginning with =,
     * +, -, @, tab or carriage return - is prefixed with a single quote so it
     * is imported as literal text. Only string values are guarded, so a native
     * number is never mangled. This mirrors the default the streaming CsvWriter
     * applies through league/csv, keeping both CSV paths safe by default.
     *
     * @param  string  $value
     * @return string
     */
    private function neutraliseFormula(string $value): string
    {
        if ($value === '' || !in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return $value;
        }

        return '\'' . $value;
    }

    /**
     * Get the configured delimiter.
     *
     * @return string
     */
    private function getDelimiter(): string
    {
        $delimiter = $this->config['delimiter'] ?? self::DEFAULT_CONFIG['delimiter'];

        return is_string($delimiter)
            ? $delimiter
            : self::DEFAULT_CONFIG['delimiter'];
    }

    /**
     * Get the configured enclosure.
     *
     * @return string
     */
    private function getEnclosure(): string
    {
        $enclosure = $this->config['enclosure'] ?? self::DEFAULT_CONFIG['enclosure'];

        return is_string($enclosure)
            ? $enclosure
            : self::DEFAULT_CONFIG['enclosure'];
    }

    /**
     * Export an iterable set of row payloads.
     *
     * @param  iterable<int, array<int|string, mixed>>  $rows
     * @return string
     */
    private function exportRows(iterable $rows): string
    {
        $lines   = [];
        $headers = false;

        foreach ($rows as $row) {

            $data = $this->filterData($row);

            if (empty($data)) {
                continue;
            }

            $this->appendHeaderLine($lines, $headers, $data);
            $lines[] = $this->generateRow($data);
        }

        return $this->buildCsvOutput($lines);
    }

    /**
     * Append a header line when needed.
     *
     * @param  array<int, string>  $lines
     * @param  bool  $headers
     * @param  array<string, scalar|null>  $data
     * @return void
     */
    private function appendHeaderLine(array &$lines, bool &$headers, array $data): void
    {
        if ($headers) {
            return;
        }

        $columns = $this->generateColumns(array_keys($data));

        if ($columns !== '') {
            $lines[] = $columns;
        }

        $headers = true;
    }

    /**
     * Build the final CSV output string.
     *
     * @param  array<int, string>  $lines
     * @return string
     */
    private function buildCsvOutput(array $lines): string
    {
        return empty($lines) ? '' : implode("\n", $lines) . "\n";
    }
}
