<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Contracts;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Exporter interface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface Exporter
{
    /**
     * Get the exporter configuration options.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array;

    /**
     * Ensure the export does not contain any of the given fields.
     *
     * @param  array<int, string>|string  $fields
     * @return static
     */
    public function withoutFields(array|string $fields): static;

    /**
     * Export the given data array.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return string
     */
    public function exportArray(array $rows): string;

    /**
     * Export the given resource item.
     *
     * @param  \Illuminate\Http\Resources\Json\JsonResource  $resource
     * @return string
     */
    public function exportItem(JsonResource $resource): string;

    /**
     * Export the given resource collection.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
     * @return string
     */
    public function exportCollection(ResourceCollection $collection): string;
}
