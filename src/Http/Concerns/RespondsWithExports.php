<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Http\Concerns;

use SineMacula\Exporter\Http\ExportNegotiator;
use SineMacula\Exporter\Http\ExportResourceCollection;

/**
 * Responds with exports.
 *
 * Mixed into a JsonResource to make both the single item and its collections
 * content-negotiable. toResponse() lets the negotiator stream a tabular export
 * when one is requested and otherwise defers fully to the framework's JSON
 * response (preserving status and withResponse() behaviour), ensuring Vary:
 * Accept is set on that JSON variant too so caches cannot serve the wrong
 * shape.
 *
 * newCollection() MUST be protected static: JsonResource::collection() calls
 * static::newCollection(), so a public instance method would not override it
 * and would fatal. Overriding it returns an export-aware collection whose own
 * toResponse() negotiates - a trait on the item alone can never intercept the
 * AnonymousResourceCollection a collection() call produces.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait RespondsWithExports
{
    /**
     * Create an HTTP response that represents the resource, negotiating an
     * export format when one is requested.
     *
     * @param  mixed  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    #[\Override]
    public function toResponse(mixed $request) // @phpstan-ignore method.childReturnType
    {
        return app()->make(ExportNegotiator::class)->item($this, $request)
            ?? ExportNegotiator::varyAccept(parent::toResponse($request));
    }

    /**
     * Create an export-aware collection for this resource.
     *
     * @param  mixed  $resource
     * @return \SineMacula\Exporter\Http\ExportResourceCollection
     */
    #[\Override]
    protected static function newCollection(mixed $resource): ExportResourceCollection
    {
        return new ExportResourceCollection($resource, static::class);
    }
}
