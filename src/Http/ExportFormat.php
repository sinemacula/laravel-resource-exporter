<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Http;

use SineMacula\Exporter\Contracts\Format;
use SineMacula\Exporter\Contracts\HierarchicalWriter;
use SineMacula\Exporter\Contracts\Writer;

/**
 * Export format descriptor.
 *
 * A concrete, immutable Format: its short name, file extension, canonical media
 * type, the media types that resolve to it, whether it is tabular, and an
 * optional writer factory. Hierarchical formats (such as JSON) carry no writer
 * factory and defer to the resource's own JSON response. The factory builds a
 * fresh, stateless writer per export so the format is safe to share under
 * Octane.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ExportFormat implements Format
{
    /**
     * Create a new export format descriptor.
     *
     * @param  string  $name
     * @param  string  $extension
     * @param  string  $defaultMediaType
     * @param  list<string>  $mediaTypes
     * @param  bool  $tabular
     * @param  (\Closure(): \SineMacula\Exporter\Contracts\Writer)|null  $writerFactory
     * @param  (\Closure(): \SineMacula\Exporter\Contracts\HierarchicalWriter)|null  $hierarchicalWriterFactory
     */
    public function __construct(

        /** The short name of the format. */
        private string $name,

        /** The file extension, without the leading dot. */
        private string $extension,

        /** The canonical media type used on negotiated responses. */
        private string $defaultMediaType,

        /** @var list<string> Every media type that resolves to this format */
        private array $mediaTypes,

        /** Whether the format is tabular and requires a schema. */
        private bool $tabular,

        /** @var (\Closure(): \SineMacula\Exporter\Contracts\Writer)|null The per-export tabular writer factory */
        private ?\Closure $writerFactory = null,

        /** @var (\Closure(): \SineMacula\Exporter\Contracts\HierarchicalWriter)|null The per-export hierarchical writer factory */
        private ?\Closure $hierarchicalWriterFactory = null,
    ) {}

    /**
     * Get the short name of the format (e.g. "csv").
     *
     * @return string
     */
    #[\Override]
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Get the file extension for the format, without the leading dot.
     *
     * @return string
     */
    #[\Override]
    public function extension(): string
    {
        return $this->extension;
    }

    /**
     * Get the canonical media type used on negotiated responses.
     *
     * @return string
     */
    #[\Override]
    public function defaultMediaType(): string
    {
        return $this->defaultMediaType;
    }

    /**
     * Get every media type that resolves to this format.
     *
     * @return list<string>
     */
    #[\Override]
    public function mediaTypes(): array
    {
        return $this->mediaTypes;
    }

    /**
     * Determine whether the format is tabular (requires a TabularSchema).
     *
     * @return bool
     */
    #[\Override]
    public function isTabular(): bool
    {
        return $this->tabular;
    }

    /**
     * Build a fresh tabular writer for the format, or null when it has none.
     *
     * @return \SineMacula\Exporter\Contracts\Writer|null
     */
    public function writer(): ?Writer
    {
        return $this->writerFactory !== null
            ? ($this->writerFactory)()
            : null;
    }

    /**
     * Build a fresh hierarchical writer for the format, or null when it has
     * none (a hierarchical format without a writer defers to the resource's own
     * JSON response).
     *
     * @return \SineMacula\Exporter\Contracts\HierarchicalWriter|null
     */
    public function hierarchicalWriter(): ?HierarchicalWriter
    {
        return $this->hierarchicalWriterFactory !== null
            ? ($this->hierarchicalWriterFactory)()
            : null;
    }
}
