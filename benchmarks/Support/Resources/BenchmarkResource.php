<?php

declare(strict_types = 1);

namespace Benchmarks\Support\Resources;

use Benchmarks\Support\Schema\MixedSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SineMacula\Exporter\Contracts\ProvidesTabularExport;
use SineMacula\Exporter\Schema\TabularSchema;

/**
 * Resource fixture for fluent builder and hierarchical benchmarks.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class BenchmarkResource extends JsonResource implements ProvidesTabularExport
{
    /**
     * Resolve the hierarchical resource shape.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, array<string, float|int|string>|bool|float|int|list<string>|string|null>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        $id      = $this->intAttribute('id');
        $balance = $this->floatAttribute('balance');

        return [
            'id'        => $id,
            'name'      => $this->stringAttribute('first_name') . ' ' . $this->stringAttribute('last_name'),
            'email'     => $this->stringAttribute('email'),
            'active'    => $this->isAttributeTrue('is_active'),
            'balance'   => $balance,
            'reference' => $this->nullableStringAttribute('reference'),
            'tags'      => ['alpha', 'beta', 'gamma'],
            'profile'   => [
                'score'      => $balance,
                'department' => 'Dept ' . (($id % 10) + 1),
            ],
        ];
    }

    /**
     * Get the tabular schema for the resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \SineMacula\Exporter\Schema\TabularSchema
     */
    #[\Override]
    public function tabular(Request $request): TabularSchema
    {
        return new MixedSchema($request);
    }

    /**
     * Read a string attribute from the resource payload.
     *
     * @param  string  $key
     * @return string
     */
    private function stringAttribute(string $key): string
    {
        $value = data_get($this->resource, $key);

        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Read a nullable string attribute from the resource payload.
     *
     * @param  string  $key
     * @return string|null
     */
    private function nullableStringAttribute(string $key): ?string
    {
        $value = data_get($this->resource, $key);

        if ($value === null) {
            return null;
        }

        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }

    /**
     * Read an integer attribute from the resource payload.
     *
     * @param  string  $key
     * @return int
     */
    private function intAttribute(string $key): int
    {
        $value = data_get($this->resource, $key);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Read a float attribute from the resource payload.
     *
     * @param  string  $key
     * @return float
     */
    private function floatAttribute(string $key): float
    {
        $value = data_get($this->resource, $key);

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * Read a boolean attribute from the resource payload.
     *
     * @param  string  $key
     * @return bool
     */
    private function isAttributeTrue(string $key): bool
    {
        return filter_var(data_get($this->resource, $key), FILTER_VALIDATE_BOOLEAN);
    }
}
