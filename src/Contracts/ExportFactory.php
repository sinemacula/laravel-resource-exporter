<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use SineMacula\Exporter\Export\QueuedExport;
use SineMacula\Exporter\ExportBuilder;

/**
 * Export factory contract.
 *
 * The public surface of the export manager, bound in the container under this
 * interface so the documented fluent entry points resolve the same singleton
 * whether a consumer type-hints the interface or the concrete manager.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface ExportFactory
{
    /**
     * Begin a fluent explicit export for the given subject.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>|\Illuminate\Http\Resources\Json\JsonResource  $subject
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>|null  $resource
     * @return \SineMacula\Exporter\ExportBuilder
     */
    public function export(Builder|JsonResource $subject, ?string $resource = null): ExportBuilder;

    /**
     * Begin a fluent explicit export for a resource collection.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
     * @return \SineMacula\Exporter\ExportBuilder
     */
    public function collection(ResourceCollection $collection): ExportBuilder;

    /**
     * Begin a fluent explicit export streaming a full query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resource
     * @return \SineMacula\Exporter\ExportBuilder
     */
    public function query(Builder $query, string $resource): ExportBuilder;

    /**
     * Begin a fluent queued export for the given model and resource class.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resource
     * @return \SineMacula\Exporter\Export\QueuedExport
     */
    public function queue(string $model, string $resource): QueuedExport;
}
