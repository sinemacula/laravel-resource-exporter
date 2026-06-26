<?php

declare(strict_types = 1);

namespace Tests\Support\Exporters;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use SineMacula\Exporter\Exporters\Xml;

/**
 * XML harness exposing protected methods.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class XmlTestHarness extends Xml
{
    /** @var bool */
    private bool $forceDomSaveFailure = false;

    /** @var false|string|null */
    private false|string|null $xmlStringOverride = null;

    /**
     * Expose protected resource item handling.
     *
     * @param  \Illuminate\Http\Resources\Json\JsonResource  $resource
     * @param  string|null  $key
     * @param  \SimpleXMLElement|null  $xml
     * @return void
     */
    public function exposeHandleResourceItem(JsonResource $resource, ?string $key = null, ?\SimpleXMLElement $xml = null): void
    {
        $this->handleResourceItem($resource, $key, $xml);
    }

    /**
     * Expose protected resource collection handling.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
     * @param  string|null  $key
     * @param  \SimpleXMLElement|null  $xml
     * @return void
     */
    public function exposeHandleResourceCollection(ResourceCollection $collection, ?string $key = null, ?\SimpleXMLElement $xml = null): void
    {
        $this->handleResourceCollection($collection, $key, $xml);
    }

    /**
     * Expose protected collection naming logic.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
     * @return string
     */
    public function exposeGetResourceNameFromCollection(ResourceCollection $collection): string
    {
        return $this->getResourceNameFromCollection($collection);
    }

    /**
     * Expose protected PascalCase conversion.
     *
     * @param  string  $string
     * @return string
     */
    public function exposeConvertToPascalCase(string $string): string
    {
        return $this->convertToPascalCase($string);
    }

    /**
     * Expose protected array-to-XML conversion.
     *
     * @param  array<int|string, mixed>  $data
     * @param  \SimpleXMLElement  $xml
     * @return void
     */
    public function exposeArrayToXml(array $data, \SimpleXMLElement $xml): void
    {
        $this->arrayToXml($data, $xml);
    }

    /**
     * Expose protected XML formatter.
     *
     * @param  \SimpleXMLElement  $xml
     * @return string
     */
    public function exposeFormatXml(\SimpleXMLElement $xml): string
    {
        return $this->formatXml($xml);
    }

    /**
     * Expose protected data filtering.
     *
     * @param  array<int|string, mixed>  $data
     * @return array<string, mixed>
     */
    public function exposeFilterData(array $data): array
    {
        return $this->filterData($data);
    }

    /**
     * Expose protected XML key normalization.
     *
     * @param  string  $key
     * @param  string  $fallback
     * @return string
     */
    public function exposeNormalizeXmlKey(string $key, string $fallback = 'Item'): string
    {
        return $this->normalizeXmlKey($key, $fallback);
    }

    /**
     * Force the DOM save step to fail.
     *
     * @return void
     */
    public function forceDomSaveFailure(): void
    {
        $this->forceDomSaveFailure = true;
    }

    /**
     * Override XML-string source for parsing/serialization failure tests.
     *
     * @param  false|string|null  $xmlString
     * @return void
     */
    public function setXmlStringOverride(false|string|null $xmlString): void
    {
        $this->xmlStringOverride = $xmlString;
    }

    /**
     * Override XML read behavior for deterministic failure coverage.
     *
     * @param  \SimpleXMLElement  $xml
     * @return false|string
     */
    #[\Override]
    protected function readXmlString(\SimpleXMLElement $xml): false|string
    {
        if ($this->xmlStringOverride !== null) {
            return $this->xmlStringOverride;
        }

        return parent::readXmlString($xml);
    }

    /**
     * Override DOM save behavior for failure-path coverage.
     *
     * @param  \DOMDocument  $dom
     * @return false|string
     */
    #[\Override]
    protected function saveDomDocument(\DOMDocument $dom): false|string
    {
        if ($this->forceDomSaveFailure) {
            return false;
        }

        return parent::saveDomDocument($dom);
    }
}
