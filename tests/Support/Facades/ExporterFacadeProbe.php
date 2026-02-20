<?php

declare(strict_types = 1);

namespace Tests\Support\Facades;

use SineMacula\Exporter\Facades\Exporter as ExporterFacade;

/**
 * Facade probe exposing the protected accessor method.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class ExporterFacadeProbe extends ExporterFacade
{
    /**
     * Expose the protected facade accessor for testing.
     *
     * @return string
     */
    public static function accessor(): string
    {
        return parent::getFacadeAccessor();
    }
}
