<?php

declare(strict_types = 1);

namespace Tests\Unit\Writers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Exceptions\XmlExportException;
use SineMacula\Exporter\Sinks\StringSink;
use SineMacula\Exporter\Writers\XmlWriter;
use Tests\Support\V3\Enums\Role;

/**
 * Golden-output tests for the streaming XML writer.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(XmlWriter::class)]
final class XmlWriterTest extends TestCase
{
    /** @var string The XML prolog every document opens with */
    private const string PROLOG = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";

    /**
     * It wraps each item in the root and item elements, nests associative
     * arrays, repeats list entries, escapes markup and preserves UTF-8.
     *
     * @return void
     */
    public function testGoldenNestedListsScalarsEscapingAndUtf8(): void
    {
        $output = $this->write(new XmlWriter, [
            ['id' => 1, 'name' => 'Alice', 'active' => true, 'tags' => ['a', 'b'], 'address' => ['city' => 'Köln', 'zip' => null]],
            ['id' => 2, 'name' => 'Bob & <Co>', 'active' => false, 'tags' => [], 'address' => ['city' => 'NY', 'zip' => '10001']],
        ]);

        self::assertSame(
            self::PROLOG
            . '<data>'
            . '<item><id>1</id><name>Alice</name><active>true</active><tags>a</tags><tags>b</tags><address><city>Köln</city><zip/></address></item>'
            . '<item><id>2</id><name>Bob &amp; &lt;Co&gt;</name><active>false</active><tags/><address><city>NY</city><zip>10001</zip></address></item>'
            . "</data>\n",
            $output,
        );
    }

    /**
     * It honours configured root and item element names.
     *
     * @return void
     */
    public function testCustomRootAndItemElements(): void
    {
        $output = $this->write(new XmlWriter(root: 'users', item: 'user'), [
            ['id' => 1, 'name' => 'A'],
        ]);

        self::assertSame(
            self::PROLOG . "<users><user><id>1</id><name>A</name></user></users>\n",
            $output,
        );
    }

    /**
     * It emits a self-closing root for an empty set.
     *
     * @return void
     */
    public function testEmptySetProducesSelfClosingRoot(): void
    {
        self::assertSame(self::PROLOG . "<data/>\n", $this->write(new XmlWriter, []));
    }

    /**
     * It sanitises data-derived keys that are not valid XML element names.
     *
     * @return void
     */
    public function testSanitisesInvalidElementNames(): void
    {
        $output = $this->write(new XmlWriter, [
            ['first name' => 'X', '123bad' => 'Y'],
        ]);

        self::assertStringContainsString('<first_name>X</first_name>', $output);
        self::assertStringContainsString('<_123bad>Y</_123bad>', $output);
    }

    /**
     * It renders backed enums and date-times as their scalar text.
     *
     * @return void
     */
    public function testRendersEnumAndDateTimeScalars(): void
    {
        $output = $this->write(new XmlWriter, [
            ['role' => Role::ADMIN, 'when' => new \DateTimeImmutable('2026-01-02T03:04:05+00:00')],
        ]);

        self::assertStringContainsString('<role>admin</role>', $output);
        self::assertStringContainsString('<when>2026-01-02T03:04:05+00:00</when>', $output);
    }

    /**
     * It indents the document when indentation is enabled.
     *
     * @return void
     */
    public function testIndentToggle(): void
    {
        $output = $this->write(new XmlWriter(indent: true), [['id' => 1]]);

        self::assertSame(
            self::PROLOG . "<data>\n <item>\n  <id>1</id>\n </item>\n</data>\n",
            $output,
        );
    }

    /**
     * It produces a document a strict XML parser can read back.
     *
     * @return void
     */
    public function testStreamsParsableXml(): void
    {
        $output = $this->write(new XmlWriter, [
            ['id' => 1, 'name' => 'Bob & <Co>', 'address' => ['city' => 'Köln']],
        ]);

        $document = simplexml_load_string($output);

        self::assertNotFalse($document);
        self::assertSame('Bob & <Co>', (string) $document->item->name);
        self::assertSame('Köln', (string) $document->item->address->city);
    }

    /**
     * It rejects an invalid configured root element name up front.
     *
     * @return void
     */
    public function testInvalidRootElementNameThrows(): void
    {
        $this->expectException(XmlExportException::class);
        $this->expectExceptionMessage('Invalid XML element name [1bad].');

        new XmlWriter(root: '1bad');
    }

    /**
     * It rejects an invalid configured item element name up front.
     *
     * @return void
     */
    public function testInvalidItemElementNameThrows(): void
    {
        $this->expectException(XmlExportException::class);

        new XmlWriter(item: 'bad name');
    }

    /**
     * It reports the XML media type.
     *
     * @return void
     */
    public function testMediaType(): void
    {
        self::assertSame('application/xml', (new XmlWriter)->mediaType());
    }

    /**
     * Write the given items through the writer and return the buffered output.
     *
     * @param  \SineMacula\Exporter\Writers\XmlWriter  $writer
     * @param  list<array<array-key, mixed>>  $items
     * @return string
     */
    private function write(XmlWriter $writer, array $items): string
    {
        $sink = new StringSink;

        $writer->write($items, $sink);

        return $sink->contents();
    }
}
