<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Sources;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use SineMacula\Exporter\Contracts\Source;

/**
 * Source adapter factory.
 *
 * Normalises an explicit export subject - a resource item, a resource
 * collection, or an Eloquent query - into the matching Source adapter so the
 * fluent builder never has to reason about adapters itself. A query is iterated
 * with keyset chunks; a collection and an item unwrap their underlying domain
 * items without serialising through the resource.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SourceFactory
{
    /**
     * Build the Source adapter for the given export subject.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>|\Illuminate\Http\Resources\Json\JsonResource  $subject
     * @param  int  $chunkSize
     * @return \SineMacula\Exporter\Contracts\Source
     */
    public static function for(Builder|JsonResource $subject, int $chunkSize): Source
    {
        return match (true) {
            $subject instanceof ResourceCollection => new ResourceCollectionSource($subject),
            $subject instanceof Builder            => new QueryChunkSource($subject, $chunkSize),
            default                                => new ResourceItemSource($subject),
        };
    }
}
