<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Contracts;

use Illuminate\Http\Request;
use SineMacula\Exporter\Schema\TabularSchema;

/**
 * Provides tabular export contract.
 *
 * Implemented by a JsonResource (or sibling) that can describe a tabular
 * representation of itself. The schema is request-aware so column visibility,
 * locale, timezone, and policy decisions resolve against the current request
 * rather than being pulled from the container (Octane-safe).
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface ProvidesTabularExport
{
    /**
     * Get the tabular schema for the current request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \SineMacula\Exporter\Schema\TabularSchema
     */
    public function tabular(Request $request): TabularSchema;
}
