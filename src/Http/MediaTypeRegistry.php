<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Http;

use SineMacula\Exporter\Contracts\HierarchicalWriter;
use SineMacula\Exporter\Contracts\Writer;
use SineMacula\Exporter\Writers\CsvWriter;
use SineMacula\Exporter\Writers\JsonWriter;
use SineMacula\Exporter\Writers\NdjsonWriter;
use SineMacula\Exporter\Writers\TsvWriter;
use SineMacula\Exporter\Writers\XlsxWriter;
use SineMacula\Exporter\Writers\XmlWriter;

/**
 * Media type registry.
 *
 * The bidirectional, extensible map between negotiable export formats and the
 * media types and URL extensions that resolve to them. It seeds the built-in
 * formats (text/csv -> csv, text/tab-separated-values -> tsv, and
 * application/json -> json as the first-class default) and lets applications
 * and third-party drivers register their own. The registry is request-stateless
 * and derived from configuration, so it is safe to share under Octane.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class MediaTypeRegistry
{
    /** @var array<string, \SineMacula\Exporter\Http\ExportFormat> The registered formats keyed by short name */
    private array $formats = [];

    /** @var array<string, string> The media type to format-name lookup */
    private array $mediaTypeMap = [];

    /** @var array<string, string> The extension to format-name lookup */
    private array $extensionMap = [];

    /** @var string The format negotiated when nothing else matches */
    private string $default = 'json';

    /**
     * Create a new registry seeded with the built-in formats.
     */
    public function __construct()
    {
        $this->register(new ExportFormat('json', 'json', 'application/json', ['application/json'], false, null, static fn (): HierarchicalWriter => new JsonWriter));
        $this->register(new ExportFormat('csv', 'csv', 'text/csv', ['text/csv'], true, static fn (): Writer => new CsvWriter));
        $this->register(new ExportFormat('tsv', 'tsv', 'text/tab-separated-values', ['text/tab-separated-values'], true, static fn (): Writer => new TsvWriter));
        $this->register(new ExportFormat(
            'xlsx',
            'xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            true,
            static fn (): Writer => new XlsxWriter,
        ));
        $this->register(new ExportFormat('xml', 'xml', 'application/xml', ['application/xml', 'text/xml'], false, null, static fn (): HierarchicalWriter => new XmlWriter));
        $this->register(new ExportFormat(
            'ndjson',
            'ndjson',
            'application/x-ndjson',
            ['application/x-ndjson', 'application/jsonl'],
            false,
            null,
            static fn (): HierarchicalWriter => new NdjsonWriter,
        ));
    }

    /**
     * Register an export format, replacing any format of the same name.
     *
     * @param  \SineMacula\Exporter\Http\ExportFormat  $format
     * @return $this
     */
    public function register(ExportFormat $format): static
    {
        $this->formats[$format->name()] = $format;

        foreach ($format->mediaTypes() as $mediaType) {
            $this->mediaTypeMap[strtolower($mediaType)] = $format->name();
        }

        $this->extensionMap[strtolower($format->extension())] = $format->name();

        return $this;
    }

    /**
     * Set the default format negotiated when nothing else matches.
     *
     * @param  string  $name
     * @return $this
     */
    public function setDefault(string $name): static
    {
        $this->default = $name;

        return $this;
    }

    /**
     * Get the name of the default format.
     *
     * @return string
     */
    public function defaultFormat(): string
    {
        return $this->default;
    }

    /**
     * Get every registered format, in registration order.
     *
     * @return list<\SineMacula\Exporter\Http\ExportFormat>
     */
    public function all(): array
    {
        return array_values($this->formats);
    }

    /**
     * Determine whether a format of the given name is registered.
     *
     * @param  string  $name
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->formats[$name]);
    }

    /**
     * Get a registered format by name.
     *
     * @param  string  $name
     * @return \SineMacula\Exporter\Http\ExportFormat|null
     */
    public function get(string $name): ?ExportFormat
    {
        return $this->formats[$name] ?? null;
    }

    /**
     * Resolve a format name from a media type, ignoring media parameters.
     *
     * @param  string  $mediaType
     * @return string|null
     */
    public function formatForMediaType(string $mediaType): ?string
    {
        $normalised = strtolower(trim(explode(';', $mediaType, 2)[0]));

        return $this->mediaTypeMap[$normalised] ?? null;
    }

    /**
     * Resolve a format name from a whitelisted URL extension.
     *
     * @param  string  $extension
     * @return string|null
     */
    public function formatForExtension(string $extension): ?string
    {
        return $this->extensionMap[strtolower($extension)] ?? null;
    }

    /**
     * Determine whether the named format is tabular.
     *
     * @param  string  $name
     * @return bool
     */
    public function isTabular(string $name): bool
    {
        return ($this->formats[$name] ?? null)?->isTabular() ?? false;
    }

    /**
     * Build a fresh tabular writer for the named format, or null when it has
     * none.
     *
     * @param  string  $name
     * @return \SineMacula\Exporter\Contracts\Writer|null
     */
    public function writerFor(string $name): ?Writer
    {
        return ($this->formats[$name] ?? null)?->writer();
    }

    /**
     * Build a fresh hierarchical writer for the named format, or null when it
     * has none.
     *
     * @param  string  $name
     * @return \SineMacula\Exporter\Contracts\HierarchicalWriter|null
     */
    public function hierarchicalWriterFor(string $name): ?HierarchicalWriter
    {
        return ($this->formats[$name] ?? null)?->hierarchicalWriter();
    }
}
