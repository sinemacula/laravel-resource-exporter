<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Http;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Export-aware resource collection.
 *
 * The collection returned in place of an AnonymousResourceCollection when a
 * resource uses the RespondsWithExports trait. Its toResponse() negotiates the
 * requested format: a tabular format streams the underlying items through a
 * Source and Writer (never resolve()/toArray(), which would buffer the whole
 * set) and yields a 406 when the item resource provides no tabular schema; any
 * other format defers to the framework's JSON collection response with Vary:
 * Accept set so caches cannot serve the wrong representation.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ExportResourceCollection extends AnonymousResourceCollection
{
    /**
     * Create an HTTP response that represents the collection, negotiating an
     * export format when one is requested.
     *
     * @param  mixed  $request
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @phpstan-ignore method.childReturnType
     */
    #[\Override]
    public function toResponse(mixed $request)
    {
        return app()->make(ExportNegotiator::class)->collection($this, $this->collects, $request)
            ?? ExportNegotiator::varyAccept(parent::toResponse($request));
    }
}
