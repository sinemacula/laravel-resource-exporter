<?php

namespace SineMacula\Exporter\Contracts;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Exporter interface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
interface Exporter
{
    /**
     * Get the exporter configuration options.
     *
     * @return array
     */
    public function getConfig(): array;

    /**
     * Ensure the export does not contain any of the given fields.
     *
     * @param  string|array  $fields
     * @return static
     */
    public function withoutFields(string|array $fields): static;

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
