<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Contracts;

/**
 * Hierarchical writer contract.
 *
 * The sibling of the tabular Writer for hierarchical formats (XML, JSON,
 * NDJSON). Where a tabular writer consumes rows shaped by a TabularSchema, a
 * hierarchical writer consumes each item's already-resolved hierarchical array
 * - the same toArray($request) shape the resource serialises for JSON - and
 * streams it into bytes for a single media type. It therefore needs no schema
 * and does not 406 a resource that lacks one. A writer is constructed per
 * export, holds no request state, and serialises one item at a time so the
 * response stays at constant memory.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface HierarchicalWriter
{
    /**
     * Get the media type this writer emits.
     *
     * @return string
     */
    public function mediaType(): string;

    /**
     * Write the resolved hierarchical items into the given sink.
     *
     * @param  iterable<int, array<array-key, mixed>>  $items
     * @param  \SineMacula\Exporter\Contracts\Sink  $sink
     * @return void
     */
    public function write(iterable $items, Sink $sink): void;
}
