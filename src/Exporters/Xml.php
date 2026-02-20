<?php

namespace SineMacula\Exporter\Exporters;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use SineMacula\Exporter\Contracts\Exporter as ExporterContract;
use SineMacula\Exporter\Exceptions\XmlExportException;

/**
 * The XML exporter.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class Xml extends Exporter implements ExporterContract
{
    /** @var array<string, mixed> The default configuration */
    protected const array DEFAULT_CONFIG = [
        'root_element'          => null,
        'pretty_print'          => true,
        'include_sub_resources' => true,
    ];

    /** @var \SimpleXMLElement|null The xml object */
    protected ?\SimpleXMLElement $xml = null;

    /**
     * Export a raw array of associative arrays to XML.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  string|null  $root
     * @param  string|null  $item
     * @return string
     */
    #[\Override]
    public function exportArray(array $rows, ?string $root = 'Items', ?string $item = 'Item'): string
    {
        $root_name = $this->normalizeXmlKey($root ?? 'Items', 'Items');
        $item_name = $this->normalizeXmlKey($item ?? 'Item', 'Item');

        $xml       = new \SimpleXMLElement("<{$root_name}/>");
        $this->xml = $xml;

        foreach ($rows as $row) {

            $data  = $this->filterData($row);
            $child = $xml->addChild($item_name);

            $this->arrayToXml($data, $child);
        }

        return $this->formatXml($xml);
    }

    /**
     * Export the given resource item.
     *
     * @param  \Illuminate\Http\Resources\Json\JsonResource  $resource
     * @return string
     */
    #[\Override]
    public function exportItem(JsonResource $resource): string
    {
        $this->handleResourceItem($resource, $this->config['root_element']);

        return $this->formatXml($this->getXml());
    }

    /**
     * Export the given resource collection.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
     * @return string
     */
    #[\Override]
    public function exportCollection(ResourceCollection $collection): string
    {
        $this->handleResourceCollection($collection, $this->config['root_element']);

        return $this->formatXml($this->getXml());
    }

    /**
     * Handle the conversion of a JsonResource to XML.
     *
     * @param  \Illuminate\Http\Resources\Json\JsonResource  $resource
     * @param  string|null  $key
     * @param  \SimpleXMLElement|null  $xml
     * @return void
     */
    protected function handleResourceItem(JsonResource $resource, ?string $key = null, ?\SimpleXMLElement $xml = null): void
    {
        $key ??= class_basename($resource->resource);
        $key = $this->normalizeXmlKey($key);

        if (is_null($xml)) {
            $this->xml = new \SimpleXMLElement('<' . $key . '/>');
        }

        $node = !is_null($xml)
            ? $xml->addChild($key)
            : $this->xml;

        $data = $this->filterData($resource->resolve());

        $this->arrayToXml($data, $node);
    }

    /**
     * Handle the conversion of a ResourceCollection to XML.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
     * @param  string|null  $key
     * @param  \SimpleXMLElement|null  $xml
     * @return void
     */
    protected function handleResourceCollection(ResourceCollection $collection, ?string $key = null, ?\SimpleXMLElement $xml = null): void
    {
        $key ??= $this->getResourceNameFromCollection($collection);
        $key = $this->normalizeXmlKey($key);

        if (is_null($xml)) {
            $this->xml = new \SimpleXMLElement('<' . $key . '/>');
        }

        $parent = !is_null($xml)
            ? $xml->addChild($key)
            : $this->xml;

        foreach ($collection->resolve() as $resolved_item) {

            $item_data = $this->filterData($resolved_item);
            $child     = $parent->addChild($this->normalizeXmlKey(Str::singular($key)));

            $this->arrayToXml($item_data, $child);
        }
    }

    /**
     * Return the resource name from the given collection.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
     * @return string
     */
    protected function getResourceNameFromCollection(ResourceCollection $collection): string
    {
        $resource = class_basename($collection->collects);
        $name     = substr($resource, 0, strrpos($resource, 'Resource') ?: strlen($resource));

        return $this->convertToPascalCase(Str::plural($name));
    }

    /**
     * Convert a column name or resource name to PascalCase.
     *
     * @param  string  $string
     * @return string
     */
    protected function convertToPascalCase(string $string): string
    {
        return Str::studly($string);
    }

    /**
     * Convert an array of data to XML and append it to the given XML element.
     *
     * @param  array<int|string, mixed>  $data
     * @param  \SimpleXMLElement  $xml
     * @return void
     */
    protected function arrayToXml(array $data, \SimpleXMLElement $xml): void
    {
        foreach ($data as $key => $value) {

            $key = $this->normalizeXmlKey((string) $key);

            if (is_array($value)) {
                $this->handleArrayValue($key, $value, $xml);
            } elseif ($value instanceof ResourceCollection && $this->shouldIncludeSubResources()) {
                $this->handleResourceCollection($value, $key, $xml);
            } elseif ($value instanceof JsonResource && $this->shouldIncludeSubResources()) {
                $this->handleResourceItem($value, $key, $xml);
            } elseif ($value instanceof Collection) {
                $this->handleCollectionValue($key, $value, $xml);
            } elseif ($this->isStringable($value)) {
                $xml->addChild($key, htmlspecialchars($value));
            }
        }
    }

    /**
     * Handle the conversion of an array value to XML.
     *
     * @param  string  $key
     * @param  array<string, mixed>  $value
     * @param  \SimpleXMLElement  $xml
     * @return void
     */
    protected function handleArrayValue(string $key, array $value, \SimpleXMLElement $xml): void
    {
        $node = $xml->addChild($key);

        $this->arrayToXml($value, $node);
    }

    /**
     * Handle the conversion of a Collection to XML.
     *
     * @param  string  $key
     * @param  \Illuminate\Support\Collection<int, mixed>  $value
     * @param  \SimpleXMLElement  $xml
     * @return void
     */
    protected function handleCollectionValue(string $key, Collection $value, \SimpleXMLElement $xml): void
    {
        $node = $xml->addChild($key);

        foreach ($value as $item) {
            $this->arrayToXml([$this->normalizeXmlKey(Str::singular($key)) => $item], $node);
        }
    }

    /**
     * Format the XML output, applying pretty print if configured.
     *
     * @param  \SimpleXMLElement  $xml
     * @return string
     */
    protected function formatXml(\SimpleXMLElement $xml): string
    {
        $xml_string = $this->readXmlString($xml);

        if ($xml_string === false) {
            throw new XmlExportException('Failed to convert XML to string.');
        }

        if ($this->shouldPrettyPrint()) {

            $dom                     = $this->createDomDocument();
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput       = true;

            if (!$dom->loadXML($xml_string)) {
                throw new XmlExportException('Failed to parse XML for formatting.');
            }

            $formatted_xml = $this->saveDomDocument($dom);

            if ($formatted_xml === false) {
                throw new XmlExportException('Failed to render formatted XML.');
            }

            return $formatted_xml;
        }

        return $xml_string;
    }

    /**
     * Create a DOMDocument instance for formatting XML output.
     *
     * @return \DOMDocument
     */
    protected function createDomDocument(): \DOMDocument
    {
        return new \DOMDocument('1.0', 'UTF-8');
    }

    /**
     * Read the XML string from the provided XML element.
     *
     * @param  \SimpleXMLElement  $xml
     * @return false|string
     */
    protected function readXmlString(\SimpleXMLElement $xml): false|string
    {
        return $xml->asXML();
    }

    /**
     * Save XML from the given DOMDocument.
     *
     * @param  \DOMDocument  $dom
     * @return false|string
     */
    protected function saveDomDocument(\DOMDocument $dom): false|string
    {
        return $dom->saveXML();
    }

    /**
     * Filter the data array to exclude ignored fields.
     *
     * @param  array<int|string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function filterData(array $data): array
    {
        $filtered = [];

        foreach ($data as $key => $value) {
            $field = is_int($key)
                ? (string) $key
                : $key;

            if (in_array($field, $this->ignored, true)) {
                continue;
            }

            $filtered[$field] = $value;
        }

        return $filtered;
    }

    /**
     * Normalize a value into a valid XML element name.
     *
     * @param  string  $key
     * @param  string  $fallback
     * @return string
     */
    protected function normalizeXmlKey(string $key, string $fallback = 'Item'): string
    {
        $normalized = $this->convertToPascalCase($key);

        if ($normalized === '') {
            return $fallback;
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $normalized) !== 1) {
            return $fallback;
        }

        return $normalized;
    }

    /**
     * Determine whether nested resources should be exported.
     *
     * @return bool
     */
    private function shouldIncludeSubResources(): bool
    {
        $include = $this->config['include_sub_resources'];

        return is_bool($include)
            ? $include
            : self::DEFAULT_CONFIG['include_sub_resources'];
    }

    /**
     * Determine whether XML output should be formatted.
     *
     * @return bool
     */
    private function shouldPrettyPrint(): bool
    {
        $pretty_print = $this->config['pretty_print'];

        return is_bool($pretty_print)
            ? $pretty_print
            : self::DEFAULT_CONFIG['pretty_print'];
    }

    /**
     * Get the current XML instance.
     *
     * @return \SimpleXMLElement
     */
    private function getXml(): \SimpleXMLElement
    {
        if ($this->xml instanceof \SimpleXMLElement) {
            return $this->xml;
        }

        throw new XmlExportException('XML document has not been initialized.');
    }
}
