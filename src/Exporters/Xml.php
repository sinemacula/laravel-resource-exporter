<?php

namespace SineMacula\Exporter\Exporters;

use DOMDocument;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Request;
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
        'include_sub_resources' => true,
    ];

    /**
     * Export the given resource item.
     *
     * @param  \Illuminate\Http\Resources\Json\JsonResource  $resource
     * @return string
     */
    public function exportItem(JsonResource $resource): string
    {
        $root = $this->config['root_element'] ?? $this->convertToPascalCase(class_basename($resource->resource));

        $xml = new SimpleXMLElement("<{$root}/>");

        $data = $this->filterData($resource->toArray(Request::instance()));

        $this->arrayToXml($data, $xml);

        return $this->formatXml($xml);
    }

    /**
     * Export the given resource collection.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
     * @return string
     */
    public function exportCollection(ResourceCollection $collection): string
    {
        $root = $this->config['root_element'] ?? $this->convertToPascalCase(Str::plural(class_basename($collection->collects)));

        $xml = new SimpleXMLElement("<{$root}/>");

        foreach ($collection as $resource) {
            $data = $this->filterData($resource->toArray(Request::instance()));
            $node = $xml->addChild($this->convertToPascalCase(class_basename($collection->collects)));

            $this->arrayToXml($data, $node);
        }

        return $this->formatXml($xml);
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
                $node = $xml->addChild($key);
                $this->arrayToXml($value, $node);
            } elseif ($value instanceof JsonResource && $this->config['include_sub_resources']) {
                $sub_data = $this->filterData($value->toArray(Request::instance()));
                $node     = $xml->addChild($key);
                $this->arrayToXml($sub_data, $node);
            } else {
                $xml->addChild($key, htmlspecialchars($value));
            }
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
}
