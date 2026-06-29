<?php

declare(strict_types = 1);

namespace SineMacula\Exporter;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use SineMacula\Exporter\Contracts\ExportFactory;
use SineMacula\Exporter\Export\QueuedExport;
use SineMacula\Exporter\Http\MediaTypeRegistry;

/**
 * The export manager.
 *
 * The single entry point behind the Exporter facade. It opens a fluent explicit
 * export for a resource, collection or query, and a serializable queued export
 * for a model; the content-negotiation engine and the writers do the rest. It
 * holds no per-request state, so it is safe to share under Octane.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ExportManager implements ExportFactory
{
    /**
     * Create a new export manager instance.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     */
    public function __construct(

        /** The application instance. */
        private Application $app,
    ) {}

    /**
     * Begin a fluent explicit export for the given subject.
     *
     * Accepts a resource item, a resource collection, or an Eloquent query. A
     * query subject takes the resource class describing it so the export can
     * resolve a tabular schema or hierarchical shape.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>|\Illuminate\Http\Resources\Json\JsonResource  $subject
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>|null  $resource
     * @return \SineMacula\Exporter\ExportBuilder
     */
    #[\Override]
    public function export(Builder|JsonResource $subject, ?string $resource = null): ExportBuilder
    {
        return new ExportBuilder(
            $subject,
            $resource,
            $this->app->make(MediaTypeRegistry::class),
            $this->app->make(Engine::class),
        );
    }

    /**
     * Begin a fluent explicit export for a resource collection.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
     * @return \SineMacula\Exporter\ExportBuilder
     */
    #[\Override]
    public function collection(ResourceCollection $collection): ExportBuilder
    {
        return $this->export($collection);
    }

    /**
     * Begin a fluent explicit export streaming a full query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resource
     * @return \SineMacula\Exporter\ExportBuilder
     */
    #[\Override]
    public function query(Builder $query, string $resource): ExportBuilder
    {
        return $this->export($query, $resource);
    }

    /**
     * Begin a fluent queued export for the given model and resource class.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resource
     * @return \SineMacula\Exporter\Export\QueuedExport
     */
    #[\Override]
    public function queue(string $model, string $resource): QueuedExport
    {
        return QueuedExport::forModel($model, $resource);
    }
}
