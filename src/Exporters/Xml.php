<?php

namespace SineMacula\Exporter\Exporters;

use DOMDocument;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use SimpleXMLElement;
use SineMacula\Exporter\Contracts\Exporter as ExporterContract;

/**
 * The XML exporter.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
class Xml extends Exporter implements ExporterContract
{
    /** @var array<string, mixed> The default configuration */
    protected const array DEFAULT_CONFIG = [
        'root_element'          => null,
        'pretty_print'          => true,
        'include_sub_resources' => true
    ];

    /** @var \SimpleXMLElement The xml object */
    protected SimpleXMLElement $xml;

    /**
     * Export the given resource item.
     *
     * @param  \Illuminate\Http\Resources\Json\JsonResource  $resource
     * @return string
     */
    public function exportItem(JsonResource $resource): string
    {
        $this->handleResourceItem($resource, $this->config['root_element']);

        return $this->formatXml($this->xml);
    }

    /**
     * Export the given resource collection.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
     * @return string
     */
    public function exportCollection(ResourceCollection $collection): string
    {
        $this->handleResourceCollection($collection, $this->config['root_element']);

        return $this->formatXml($this->xml);
    }

    /**
     * Handle the conversion of a JsonResource to XML.
     *
     * @param  \Illuminate\Http\Resources\Json\JsonResource  $resource
     * @param  string|null  $key
     * @param  \SimpleXMLElement|null  $xml
     * @return void
     */
    protected function handleResourceItem(JsonResource $resource, ?string $key = null, ?SimpleXMLElement $xml = null): void
    {
        $key ??= $this->convertToPascalCase(class_basename($resource->resource));

        if (is_null($xml)) {
            $this->xml = new SimpleXMLElement('<' . $key . '/>');
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
    protected function handleResourceCollection(ResourceCollection $collection, ?string $key = null, ?SimpleXMLElement $xml = null): void
    {
        $key ??= $this->getResourceNameFromCollection($collection);

        if (is_null($xml)) {
            $this->xml = new SimpleXMLElement('<' . $key . '/>');
        }

        $parent = !is_null($xml)
            ? $xml->addChild($key)
            : $this->xml;

        foreach ($collection->resolve() as $item) {

            $item  = $this->filterData($item);
            $child = $parent->addChild($this->convertToPascalCase(Str::singular($key)));

            $this->arrayToXml($item, $child);
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
     * @param  array  $data
     * @param  \SimpleXMLElement  $xml
     * @return void
     */
    protected function arrayToXml(array $data, SimpleXMLElement $xml): void
    {
        foreach ($data as $key => $value) {

            $key = $this->convertToPascalCase($key);

            if (is_array($value)) {
                $this->handleArrayValue($key, $value, $xml);
            } elseif ($value instanceof ResourceCollection && $this->config['include_sub_resources']) {
                $this->handleResourceCollection($value, $key, $xml);
            } elseif ($value instanceof JsonResource && $this->config['include_sub_resources']) {
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
     * @param  array  $value
     * @param  \SimpleXMLElement  $xml
     * @return void
     */
    protected function handleArrayValue(string $key, array $value, SimpleXMLElement $xml): void
    {
        $node = $xml->addChild($key);

        $this->arrayToXml($value, $node);
    }

    /**
     * Handle the conversion of a Collection to XML.
     *
     * @param  string  $key
     * @param  \Illuminate\Support\Collection  $value
     * @param  \SimpleXMLElement  $xml
     * @return void
     */
    protected function handleCollectionValue(string $key, Collection $value, SimpleXMLElement $xml): void
    {
        $node = $xml->addChild($key);

        foreach ($value as $item) {
            $this->arrayToXml([Str::singular($key) => $item], $node);
        }
    }

    /**
     * Format the XML output, applying pretty print if configured.
     *
     * @param  \SimpleXMLElement  $xml
     * @return string
     */
    protected function formatXml(SimpleXMLElement $xml): string
    {
        if ($this->config['pretty_print']) {

            $dom                     = new DOMDocument('1.0', 'UTF-8');
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput       = true;

            $dom->loadXML($xml->asXML());

            return $dom->saveXML();
        }

        return $xml->asXML();
    }

    /**
     * Filter the data array to exclude non-stringable values and ignored
     * fields.
     *
     * @param  array  $data
     * @return array
     */
    protected function filterData(array $data): array
    {
        return array_filter($data, function ($value, $key) {
            return !in_array($key, $this->ignored);
        }, ARRAY_FILTER_USE_BOTH);
    }
}
