<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Writers;

use SineMacula\Exporter\Contracts\HierarchicalWriter;
use SineMacula\Exporter\Contracts\Sink;
use SineMacula\Exporter\Exceptions\XmlExportException;
use SineMacula\Exporter\Writers\Concerns\WritesBytes;

/**
 * Streaming XML writer.
 *
 * Streams each item's hierarchical array as XML on top of the native XMLWriter
 * (never SimpleXML/DOM, which materialise the whole tree). Items are wrapped in
 * a configurable root element and a per-item element; nested associative arrays
 * become nested elements, numeric lists become repeated child elements, and
 * scalars become escaped text nodes. The writer buffers in memory and flushes
 * after every item, so the document streams at constant memory. It is
 * constructed per export and holds no request state, so it is Octane-safe.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class XmlWriter implements HierarchicalWriter
{
    use WritesBytes;

    /**
     * Create a new XML writer.
     *
     * @param  string  $root
     * @param  string  $item
     * @param  bool  $indent
     *
     * @throws \SineMacula\Exporter\Exceptions\XmlExportException
     */
    public function __construct(

        /** The name of the document's root element. */
        private string $root = 'data',

        /** The name of the element wrapping each item. */
        private string $item = 'item',

        /** Whether the output is indented for readability. */
        private bool $indent = false,
    ) {
        $this->guardElementName($root);
        $this->guardElementName($item);
    }

    /**
     * Get the media type this writer emits.
     *
     * @return string
     */
    #[\Override]
    public function mediaType(): string
    {
        return 'application/xml';
    }

    /**
     * Write the resolved hierarchical items into the given sink as XML.
     *
     * @param  iterable<int, array<array-key, mixed>>  $items
     * @param  \SineMacula\Exporter\Contracts\Sink  $sink
     * @return void
     *
     * @throws \Throwable
     */
    #[\Override]
    public function write(iterable $items, Sink $sink): void
    {
        $stream = $sink->stream();
        $xml    = new \XMLWriter;

        $xml->openMemory();
        $xml->setIndent($this->indent);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement($this->root);

        try {
            foreach ($items as $item) {
                $this->writeNode($xml, $this->item, $item);
                $this->writeBytes($stream, $xml->flush());
            }
        } catch (\Throwable $exception) {
            $this->markTruncated($xml, $stream);

            throw $exception;
        }

        $xml->endElement();
        $xml->endDocument();

        $this->writeBytes($stream, $xml->flush());
        fflush($stream);
    }

    /**
     * Close the document with a clearly-marked truncation element on failure.
     *
     * The streamed response has already committed its status and bytes, so the
     * open root is finished with a final marker element and closed, leaving the
     * output well-formed XML whose last child flags the truncation, before the
     * exception propagates.
     *
     * @param  \XMLWriter  $xml
     * @param  resource  $stream
     * @return void
     */
    private function markTruncated(\XMLWriter $xml, $stream): void // phpcs:ignore SineMaculaLaravel.TypeHints.ParameterTypeHint.MissingNativeTypeHint
    {
        $xml->startElement(Truncation::XML_ELEMENT);
        $xml->text(Truncation::REASON);
        $xml->endElement();

        $xml->endElement();
        $xml->endDocument();

        fwrite($stream, $xml->flush());
        fflush($stream);
    }

    /**
     * Write a single named node and its value, recursing into nested data.
     *
     * @param  \XMLWriter  $xml
     * @param  string  $name
     * @param  mixed  $value
     * @return void
     */
    private function writeNode(\XMLWriter $xml, string $name, mixed $value): void
    {
        $element = $this->elementName($name);

        if (is_array($value)) {
            $this->writeArray($xml, $element, $value);

            return;
        }

        $xml->startElement($element);

        $text = $this->scalar($value);

        if ($text !== '') {
            $xml->text($text);
        }

        $xml->endElement();
    }

    /**
     * Write an array value: a numeric list as repeated elements, an associative
     * array as a nested element, and an empty array as an empty element.
     *
     * @param  \XMLWriter  $xml
     * @param  string  $element
     * @param  array<array-key, mixed>  $value
     * @return void
     */
    private function writeArray(\XMLWriter $xml, string $element, array $value): void
    {
        if ($value === []) {
            $xml->startElement($element);
            $xml->endElement();

            return;
        }

        if (array_is_list($value)) {

            foreach ($value as $entry) {
                $this->writeNode($xml, $element, $entry);
            }

            return;
        }

        $xml->startElement($element);

        foreach ($value as $key => $child) {
            $this->writeNode($xml, (string) $key, $child);
        }

        $xml->endElement();
    }

    /**
     * Render a scalar value to its XML text representation.
     *
     * @param  mixed  $value
     * @return string
     */
    private function scalar(mixed $value): string
    {
        return match (true) {
            $value === null                      => '',
            is_bool($value)                      => $value ? 'true' : 'false',
            $value instanceof \BackedEnum        => (string) $value->value,
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
            is_scalar($value)                    => (string) $value,
            $value instanceof \Stringable        => (string) $value,
            default                              => '',
        };
    }

    /**
     * Resolve a data-derived key to a valid XML element name, sanitising any
     * character that an element name may not contain.
     *
     * @param  string  $name
     * @return string
     */
    private function elementName(string $name): string
    {
        if (preg_match('/^[A-Za-z_][\w.\-]*$/', $name) === 1) {
            return $name;
        }

        $sanitised = (string) preg_replace('/[^\w.\-]/', '_', $name);

        if ($sanitised === '' || preg_match('/^[A-Za-z_]/', $sanitised) !== 1) {
            $sanitised = '_' . $sanitised;
        }

        return $sanitised;
    }

    /**
     * Guard an author-configured element name, rejecting an invalid name up
     * front rather than emitting malformed XML.
     *
     * @param  string  $name
     * @return void
     *
     * @throws \SineMacula\Exporter\Exceptions\XmlExportException
     */
    private function guardElementName(string $name): void
    {
        if (preg_match('/^[A-Za-z_][\w.\-]*$/', $name) !== 1) {
            throw new XmlExportException("Invalid XML element name [{$name}].");
        }
    }
}
