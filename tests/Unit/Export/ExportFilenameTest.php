<?php

declare(strict_types = 1);

namespace Tests\Unit\Export;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Exceptions\NoTabularRepresentation;
use SineMacula\Exporter\Export\ExportFilename;
use SineMacula\Exporter\Http\ExportFormat;
use SineMacula\Exporter\Http\MediaTypeRegistry;

/**
 * Tests the download-naming helper.
 *
 * Resolves the media type for a format (tabular or hierarchical writer), the
 * extension-bearing download filename, and the Content-Disposition header.
 * Filenames are sanitised the way makeDisposition() demands - path and percent
 * characters replaced, a pure-ASCII fallback always supplied - so a non-ASCII
 * name never throws.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExportFilename::class)]
final class ExportFilenameTest extends TestCase
{
    /**
     * It resolves the media type from the tabular and hierarchical writers.
     *
     * @return void
     */
    public function testResolvesMediaType(): void
    {
        self::assertSame('text/csv', $this->filename('csv')->mediaType());
        self::assertSame('application/json', $this->filename('json')->mediaType());
    }

    /**
     * It throws when the format has no writer at all.
     *
     * @return void
     */
    public function testUnknownFormatHasNoMediaType(): void
    {
        $this->expectException(NoTabularRepresentation::class);

        $this->filename('does-not-exist')->mediaType();
    }

    /**
     * It builds the extension-bearing filename and sanitises path characters.
     *
     * @return void
     */
    public function testResolvesFilename(): void
    {
        self::assertSame('users.csv', $this->filename('csv')->resolve('users'));
        self::assertSame('a_b_c.csv', $this->filename('csv')->resolve('a/b\c'));
        self::assertSame('report.weird', $this->filename('weird')->resolve('report'), 'An unknown format falls back to its own name as the extension.');
    }

    /**
     * It uses the registry's extension, not the format name, for the suffix.
     *
     * Pins the coalesce so the resolved extension comes from the registered
     * format's own extension and only falls back to the format name when the
     * format is unknown.
     *
     * @return void
     */
    public function testResolveUsesTheRegistryExtensionNotTheFormatName(): void
    {
        $registry = (new MediaTypeRegistry)
            ->register(new ExportFormat('spreadsheet', 'xls', 'application/x-test', ['application/x-test'], true));

        $names = new ExportFilename($registry, 'spreadsheet');

        self::assertSame('report.xls', $names->resolve('report'));
    }

    /**
     * It builds an attachment disposition with an ASCII fallback name.
     *
     * @return void
     */
    public function testBuildsDisposition(): void
    {
        $disposition = $this->filename('csv')->disposition("caf\u{00e9}/report");

        self::assertStringStartsWith('attachment;', $disposition);
        self::assertStringContainsString('filename=caf_report.csv', $disposition);
        self::assertStringContainsString('filename*=utf-8\'\'caf%C3%A9_report.csv', $disposition);
    }

    /**
     * It replaces a percent sign in the ASCII fallback filename.
     *
     * makeDisposition() rejects an ASCII fallback that still carries a percent
     * sign, so the sanitiser must replace it; without that replacement the
     * disposition cannot be built at all.
     *
     * @return void
     */
    public function testDispositionReplacesPercentInTheAsciiFallback(): void
    {
        $disposition = $this->filename('csv')->disposition('50%done');

        self::assertSame('attachment; filename=50_done.csv; filename*=utf-8\'\'50%25done.csv', $disposition);
    }

    /**
     * Build a naming helper for the given format over a real registry.
     *
     * @param  string  $format
     * @return \SineMacula\Exporter\Export\ExportFilename
     */
    private function filename(string $format): ExportFilename
    {
        return new ExportFilename(new MediaTypeRegistry, $format);
    }
}
