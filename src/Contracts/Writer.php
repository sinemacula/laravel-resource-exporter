<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Contracts;

use SineMacula\Exporter\Schema\TabularSchema;

/**
 * Writer contract.
 *
 * Streams a tabular representation into bytes for a single media type. A writer
 * is constructed per export, holds no request state, and emits rows already
 * shaped by the schema (a map of column key to typed cell value) one at a time
 * so the response stays at constant memory.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface Writer
{
    /**
     * Get the media type this writer emits.
     *
     * @return string
     */
    public function mediaType(): string;

    /**
     * Write the shaped rows into the given sink.
     *
     * @param  iterable<int, array<string, \SineMacula\Exporter\Schema\CellValue>>  $rows
     * @param  \SineMacula\Exporter\Schema\TabularSchema  $schema
     * @param  \SineMacula\Exporter\Contracts\Sink  $sink
     * @return void
     */
    public function write(iterable $rows, TabularSchema $schema, Sink $sink): void;
}
