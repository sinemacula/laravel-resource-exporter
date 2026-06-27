<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Facades;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Facade;
use SineMacula\Exporter\Testing\ExporterFake;

/**
 * Exporter facade.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @method static \SineMacula\Exporter\Contracts\Exporter format(string|null $format)
 * @method static array<string, mixed> getConfig()
 * @method static \SineMacula\Exporter\Contracts\Exporter withoutFields(string|array<int, string> $fields)
 * @method static string exportArray(array<int, array<string, mixed> > $rows)
 * @method static string exportItem(\Illuminate\Http\Resources\Json\JsonResource $resource)
 * @method static string exportCollection(\Illuminate\Http\Resources\Json\ResourceCollection $collection)
 * @method static \SineMacula\Exporter\ExportBuilder export(mixed $subject, string|null $resource = null)
 * @method static \SineMacula\Exporter\ExportBuilder collection(\Illuminate\Http\Resources\Json\ResourceCollection $collection)
 * @method static \SineMacula\Exporter\ExportBuilder query(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query, string $resource)
 * @method static \SineMacula\Exporter\Export\QueuedExport queue(string $model, string $resource)
 *
 * @see         \SineMacula\Exporter\Exporter
 */
final class Exporter extends Facade
{
    /**
     * Swap the export pathway for a recording test double.
     *
     * Binds an ExporterFake in the container so every fluent and queued export
     * records what it would have produced instead of producing bytes,
     * dispatching jobs, or touching a disk. Returns the fake so assertions can
     * be chained, mirroring Storage::fake() and Bus::fake().
     *
     * @return \SineMacula\Exporter\Testing\ExporterFake
     */
    public static function fake(): ExporterFake
    {
        $fake = new ExporterFake;

        Container::getInstance()->instance(ExporterFake::class, $fake);

        return $fake;
    }

    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        $accessor = Config::get('exporter.alias', 'exporter');

        return is_string($accessor) ? $accessor : 'exporter';
    }
}
