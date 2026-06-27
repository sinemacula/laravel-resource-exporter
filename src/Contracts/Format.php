<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Contracts;

/**
 * Format descriptor contract.
 *
 * Describes a registered export format: its short name, file extension, the
 * media types that resolve to it, and whether it is tabular (driven by a
 * TabularSchema) or hierarchical (driven by the resource's toArray shape).
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface Format
{
    /**
     * Get the short name of the format (e.g. "csv").
     *
     * @return string
     */
    public function name(): string;

    /**
     * Get the file extension for the format, without the leading dot.
     *
     * @return string
     */
    public function extension(): string;

    /**
     * Get the canonical media type used on negotiated responses.
     *
     * @return string
     */
    public function defaultMediaType(): string;

    /**
     * Get every media type that resolves to this format.
     *
     * @return list<string>
     */
    public function mediaTypes(): array;

    /**
     * Determine whether the format is tabular (requires a TabularSchema) as
     * opposed to hierarchical.
     *
     * @return bool
     */
    public function isTabular(): bool;
}
