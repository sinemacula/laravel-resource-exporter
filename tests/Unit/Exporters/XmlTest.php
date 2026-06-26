<?php

declare(strict_types = 1);

namespace Tests\Unit\Exporters;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Exceptions\XmlExportException;
use SineMacula\Exporter\Exporters\Xml;
use Tests\Support\Exporters\XmlPlainEntityCollection;
use Tests\Support\Exporters\XmlTestHarness;
use Tests\Support\Exporters\XmlUserResource;
use Tests\Support\Exporters\XmlUserResourceCollection;
use Tests\Support\ResourceTestCase;

/**
 * Tests for XML export behavior.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Xml::class)]
#[CoversClass(XmlExportException::class)]
final class XmlTest extends ResourceTestCase
{
    /** @var string */
    private const string ROOT_NODE = '<Root/>';

    /**
     * It exports arrays with normalized root and item keys.
     *
     * @return void
     */
    public function testExportArrayNormalizesRootAndItemKeys(): void
    {
        $exporter = new Xml([
            'pretty_print' => false,
        ]);

        $exporter->withoutFields('ignored');

        $xmlString = $exporter->exportArray(
            [
                [
                    'name'    => 'Alice',
                    'ignored' => 'secret',
                ],
            ],
            '123-root',
            '',
        );

        $xml = simplexml_load_string($xmlString);

        self::assertInstanceOf(\SimpleXMLElement::class, $xml);
        self::assertSame('Items', $xml->getName());
        self::assertSame('Alice', (string) $xml->Item->Name);
        self::assertFalse(isset($xml->Item->Ignored));
    }

    /**
     * It exports a JsonResource using the configured root element.
     *
     * @return void
     */
    public function testExportItemUsesConfiguredRootElement(): void
    {
        $exporter = new Xml([
            'root_element' => 'custom-root',
            'pretty_print' => false,
        ]);

        $xmlString = $exporter->exportItem(
            new XmlUserResource(['name' => 'Alice']),
        );

        $xml = simplexml_load_string($xmlString);

        self::assertInstanceOf(\SimpleXMLElement::class, $xml);
        self::assertSame('CustomRoot', $xml->getName());
        self::assertSame('Alice', (string) $xml->Name);
    }

    /**
     * It exports collections using derived resource names.
     *
     * @return void
     */
    public function testExportCollectionUsesDerivedResourceNames(): void
    {
        $exporter = new Xml([
            'pretty_print' => false,
        ]);

        $xmlString = $exporter->exportCollection(
            new XmlUserResourceCollection([
                ['name' => 'Alice'],
                ['name' => 'Bob'],
            ]),
        );

        $xml = simplexml_load_string($xmlString);

        self::assertInstanceOf(\SimpleXMLElement::class, $xml);
        self::assertSame('XmlUsers', $xml->getName());
        self::assertSame('Alice', (string) $xml->XmlUser[0]->Name);
        self::assertSame('Bob', (string) $xml->XmlUser[1]->Name);
    }

    /**
     * It converts mixed value types into nested XML nodes.
     *
     * @return void
     */
    public function testArrayToXmlHandlesArraysCollectionsResourcesAndScalars(): void
    {
        $exporter = new XmlTestHarness([
            'pretty_print' => false,
        ]);

        $xml = new \SimpleXMLElement(self::ROOT_NODE);

        $exporter->exposeArrayToXml(
            [
                'details'    => ['age' => 30],
                'title'      => 'Matrix',
                'tags'       => new Collection(['neo', 'trinity']),
                'resource'   => new XmlUserResource(['name' => 'Neo']),
                'resources'  => new XmlUserResourceCollection([['name' => 'Morpheus']]),
                'stringable' => new class implements \Stringable {
                    /**
                     * Cast the value to string.
                     *
                     * @return string
                     */
                    #[\Override]
                    public function __toString(): string
                    {
                        return 'cast-value';
                    }
                },
                'object' => new \stdClass,
            ],
            $xml,
        );

        self::assertSame('30', (string) $xml->Details->Age);
        self::assertSame('Matrix', (string) $xml->Title);
        self::assertSame('neo', (string) $xml->Tags->Tag[0]);
        self::assertSame('trinity', (string) $xml->Tags->Tag[1]);
        self::assertSame('Neo', (string) $xml->Resource->Name);
        self::assertSame('Morpheus', (string) $xml->Resources->Resource->Name);
        self::assertSame('cast-value', (string) $xml->Stringable);
        self::assertFalse(isset($xml->Object));
    }

    /**
     * It skips nested JsonResource and ResourceCollection values when disabled.
     *
     * @return void
     */
    public function testArrayToXmlSkipsSubResourcesWhenDisabled(): void
    {
        $exporter = new XmlTestHarness([
            'include_sub_resources' => false,
            'pretty_print'          => false,
        ]);

        $xml = new \SimpleXMLElement(self::ROOT_NODE);

        $exporter->exposeArrayToXml(
            [
                'resource'  => new XmlUserResource(['name' => 'Neo']),
                'resources' => new XmlUserResourceCollection([['name' => 'Morpheus']]),
            ],
            $xml,
        );

        self::assertFalse(isset($xml->Resource));
        self::assertFalse(isset($xml->Resources));
    }

    /**
     * It handles resource item and collection nodes under an existing parent.
     *
     * @return void
     */
    public function testHandleResourceItemAndCollectionWithParentNode(): void
    {
        $exporter = new XmlTestHarness([
            'pretty_print' => false,
        ]);

        $xml = new \SimpleXMLElement(self::ROOT_NODE);

        $exporter->exposeHandleResourceItem(
            new XmlUserResource(['name' => 'Alice']),
            'item-node',
            $xml,
        );

        $exporter->exposeHandleResourceCollection(
            new XmlUserResourceCollection([['name' => 'Bob']]),
            'collection-node',
            $xml,
        );

        self::assertSame('Alice', (string) $xml->ItemNode->Name);
        self::assertSame('Bob', (string) $xml->CollectionNode->CollectionNode->Name);
    }

    /**
     * It validates key normalization and filtered payload output.
     *
     * @return void
     */
    public function testFilterDataAndKeyNormalizationHelpers(): void
    {
        $exporter = new XmlTestHarness([]);
        $exporter->withoutFields(['secret', '0']);

        self::assertSame(
            ['name' => 'Alice'],
            $exporter->exposeFilterData([
                0        => 'drop',
                'name'   => 'Alice',
                'secret' => 'hidden',
            ]),
        );

        self::assertSame(
            'ValidName',
            $exporter->exposeNormalizeXmlKey('valid-name'),
        );

        self::assertSame(
            'Fallback',
            $exporter->exposeNormalizeXmlKey('', 'Fallback'),
        );

        self::assertSame(
            'Fallback',
            $exporter->exposeNormalizeXmlKey('123-invalid', 'Fallback'),
        );
    }

    /**
     * It returns expected defaults and throws when XML is not initialized.
     *
     * @return void
     */
    public function testPrivateConfigurationHelpersAndXmlInitializationGuard(): void
    {
        $exporter = new Xml([
            'pretty_print'          => 'invalid',
            'include_sub_resources' => 'invalid',
        ]);
        $fallbackExporter = new Xml([]);

        self::assertTrue((bool) $this->invokePrivate($exporter, 'shouldPrettyPrint'));
        self::assertTrue((bool) $this->invokePrivate($exporter, 'shouldIncludeSubResources'));
        self::assertTrue((bool) $this->invokePrivate($fallbackExporter, 'shouldPrettyPrint'));
        self::assertTrue((bool) $this->invokePrivate($fallbackExporter, 'shouldIncludeSubResources'));

        $this->expectException(XmlExportException::class);
        $this->expectExceptionMessage('XML document has not been initialized.');

        $this->invokePrivate($exporter, 'getXml');
    }

    /**
     * It exposes the initialized XML object after export.
     *
     * @return void
     */
    public function testGetXmlReturnsInitializedDocument(): void
    {
        $exporter = new Xml([
            'pretty_print' => false,
        ]);

        $exporter->exportArray([['name' => 'Alice']]);

        $xml = $this->invokePrivate($exporter, 'getXml');

        self::assertInstanceOf(\SimpleXMLElement::class, $xml);
    }

    /**
     * It throws when XML cannot be rendered to a string.
     *
     * @return void
     */
    public function testFormatXmlThrowsWhenSimpleXmlAsXmlFails(): void
    {
        $exporter = new XmlTestHarness([]);
        $exporter->setXmlStringOverride(false);

        $this->expectException(XmlExportException::class);
        $this->expectExceptionMessage('Failed to convert XML to string.');

        $exporter->exposeFormatXml(new \SimpleXMLElement(self::ROOT_NODE));
    }

    /**
     * It throws when pretty-print parsing fails.
     *
     * @return void
     */
    public function testFormatXmlThrowsWhenDomDocumentLoadFails(): void
    {
        $exporter = new XmlTestHarness([
            'pretty_print' => true,
        ]);

        $exporter->setXmlStringOverride('<broken');

        $this->expectException(XmlExportException::class);
        $this->expectExceptionMessage('Failed to parse XML for formatting.');

        $previous = libxml_use_internal_errors(true);

        try {
            $exporter->exposeFormatXml(new \SimpleXMLElement(self::ROOT_NODE));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * It returns raw XML when pretty print is disabled.
     *
     * @return void
     */
    public function testFormatXmlReturnsRawXmlWhenPrettyPrintIsDisabled(): void
    {
        $exporter = new XmlTestHarness([
            'pretty_print' => false,
        ]);

        $xml = new \SimpleXMLElement('<Root><Name>Alice</Name></Root>');
        $raw = $xml->asXML();

        self::assertIsString($raw);
        self::assertSame($raw, $exporter->exposeFormatXml($xml));
    }

    /**
     * It formats XML output when pretty print is enabled.
     *
     * @return void
     */
    public function testFormatXmlReturnsPrettyPrintedOutputWhenEnabled(): void
    {
        $exporter = new XmlTestHarness([
            'pretty_print' => true,
        ]);

        $formatted = $exporter->exposeFormatXml(
            new \SimpleXMLElement('<Root><Name>Alice</Name></Root>'),
        );

        self::assertStringStartsWith('<?xml version="1.0"', $formatted);
        self::assertStringContainsString("<Root>\n", $formatted);
        self::assertStringContainsString('<Name>Alice</Name>', $formatted);
    }

    /**
     * It throws when DOM rendering fails after successful parsing.
     *
     * @return void
     */
    public function testFormatXmlThrowsWhenDomRenderingFails(): void
    {
        $exporter = new XmlTestHarness([
            'pretty_print' => true,
        ]);

        $exporter->forceDomSaveFailure();

        $this->expectException(XmlExportException::class);
        $this->expectExceptionMessage('Failed to render formatted XML.');

        $exporter->exposeFormatXml(new \SimpleXMLElement(self::ROOT_NODE));
    }

    /**
     * It derives collection names with and without a Resource suffix.
     *
     * @return void
     */
    public function testGetResourceNameFromCollectionForMultipleClassShapes(): void
    {
        $exporter = new XmlTestHarness([]);

        self::assertSame(
            'XmlUsers',
            $exporter->exposeGetResourceNameFromCollection(
                new XmlUserResourceCollection([['name' => 'Alice']]),
            ),
        );

        self::assertSame(
            'XmlPlainEntityJsons',
            $exporter->exposeGetResourceNameFromCollection(
                new XmlPlainEntityCollection([['name' => 'Alice']]),
            ),
        );

        self::assertSame('SampleValue', $exporter->exposeConvertToPascalCase('sample-value'));
    }

    /**
     * Invoke private methods for deterministic branch coverage.
     *
     * @param  \SineMacula\Exporter\Exporters\Xml  $exporter
     * @param  string  $method
     * @param  mixed  ...$arguments
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    private function invokePrivate(Xml $exporter, string $method, mixed ...$arguments): mixed
    {
        $invoker = \Closure::bind(
            static function (Xml $instance, string $name): mixed {
                return match ($name) {
                    'shouldPrettyPrint'         => $instance->shouldPrettyPrint(),
                    'shouldIncludeSubResources' => $instance->shouldIncludeSubResources(),
                    'getXml'                    => $instance->getXml(),
                    default                     => throw new \InvalidArgumentException("Unsupported private method [{$name}]."),
                };
            },
            null,
            Xml::class,
        );

        if ($arguments !== []) {
            throw new \InvalidArgumentException('Arguments are not supported in this private invoker.');
        }

        return $invoker($exporter, $method);
    }
}
