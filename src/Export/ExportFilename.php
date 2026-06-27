<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Export;

use SineMacula\Exporter\Exceptions\NoTabularRepresentation;
use SineMacula\Exporter\Http\MediaTypeRegistry;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Download naming for an explicit export.
 *
 * Resolves the media type, the extension-bearing download filename, and the
 * Content-Disposition header for a chosen format, keeping the fluent builder
 * free of header and filename plumbing. Filenames are sanitised the way
 * makeDisposition() demands: path and percent characters are replaced and a
 * pure-ASCII fallback is always supplied so a non-ASCII name never throws.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ExportFilename
{
    /**
     * Create a new download-naming helper for a format.
     *
     * @param  \SineMacula\Exporter\Http\MediaTypeRegistry  $registry
     * @param  string  $format
     */
    public function __construct(

        /** The media registry resolving writers, extensions and media types. */
        private MediaTypeRegistry $registry,

        /** The negotiated export format name. */
        private string $format,
    ) {}

    /**
     * Resolve the media type for the format.
     *
     * @return string
     *
     * @throws \SineMacula\Exporter\Exceptions\NoTabularRepresentation
     */
    public function mediaType(): string
    {
        $writer = $this->registry->isTabular($this->format)
            ? $this->registry->writerFor($this->format)
            : $this->registry->hierarchicalWriterFor($this->format);

        if ($writer === null) {
            throw NoTabularRepresentation::forResource($this->format);
        }

        return $writer->mediaType();
    }

    /**
     * Build the attachment Content-Disposition header for a filename hint.
     *
     * @param  string  $hint
     * @return string
     */
    public function disposition(string $hint): string
    {
        $name = $this->resolve($hint);

        return HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $name,
            $this->ascii($name),
        );
    }

    /**
     * Resolve the extension-bearing download filename for the format.
     *
     * @param  string  $hint
     * @return string
     */
    public function resolve(string $hint): string
    {
        $extension = $this->registry->get($this->format)?->extension() ?? $this->format;
        $base      = str_replace(['/', '\\'], '_', $hint);

        return $base . '.' . $extension;
    }

    /**
     * Build an ASCII-safe fallback filename for the disposition header.
     *
     * @param  string  $filename
     * @return string
     */
    private function ascii(string $filename): string
    {
        $ascii = (string) preg_replace('/[^\x20-\x7E]/', '', $filename);
        $ascii = str_replace(['/', '\\', '%'], '_', $ascii);

        return $ascii === '' ? 'export' : $ascii;
    }
}
