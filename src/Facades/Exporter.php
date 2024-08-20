<?php

namespace SineMacula\ApiToolkit\Facades;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Facade;
use SineMacula\Exporter\Contracts\Exporter as ExporterContract;

/**
 * Exporter facade.
 *
 * @method static ExporterContract format(string|null $format)
 * @method static array getConfig()
 * @method static ExporterContract withoutFields(string|array $fields)
 * @method static string exportItem(JsonResource $resource)
 * @method static string exportCollection(ResourceCollection $collection)
 *
 * @see         \SineMacula\Exporter\Exporter
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
class Exporter extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return Config::get('exporter.alias', 'exporter');
    }
}
