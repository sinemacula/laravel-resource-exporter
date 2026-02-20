<?php

declare(strict_types = 1);

namespace Tests\Support;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use SineMacula\Exporter\Contracts\Exporter as ExporterContract;

/**
 * Fake exporter for custom manager creator tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class ExportManagerFakeExporter implements ExporterContract
{
    /**
     * Create a fake exporter instance.
     *
     * @param  array<string, mixed>  $config
     */
    public function __construct(

        /** @var array<string, mixed> */
        private array $config,

    ) {}

    /**
     * Return exporter config.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Ignore fields for chained calls.
     *
     * @param  array<int, string>|string  $fields
     * @return static
     */
    #[\Override]
    public function withoutFields(array|string $fields): static
    {
        return $this;
    }

    /**
     * Export an array payload.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return string
     */
    #[\Override]
    public function exportArray(array $rows): string
    {
        return '';
    }

    /**
     * Export a JsonResource payload.
     *
     * @param  \Illuminate\Http\Resources\Json\JsonResource  $resource
     * @return string
     */
    #[\Override]
    public function exportItem(JsonResource $resource): string
    {
        return '';
    }

    /**
     * Export a ResourceCollection payload.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
     * @return string
     */
    #[\Override]
    public function exportCollection(ResourceCollection $collection): string
    {
        return '';
    }
}
