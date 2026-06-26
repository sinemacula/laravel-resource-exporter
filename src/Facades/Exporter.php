<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Facades;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Facade;

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
 *
 * @see         \SineMacula\Exporter\Exporter
 */
final class Exporter extends Facade
{
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
