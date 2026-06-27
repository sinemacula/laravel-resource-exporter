<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Testing;

use SineMacula\Exporter\Export\ExportSpecification;

/**
 * Recorded export.
 *
 * One immutable entry in the ExporterFake ledger: the verb that produced it
 * (download, store, string, stream, or queue), the negotiated format, the
 * download filename hint and its resolved extension-bearing name, the target
 * disk and path for a stored export, the number of source rows the export would
 * have emitted, and the serializable specification for a queued export. The
 * fake records one of these per terminal verb so the test-time assertions can
 * reason about what an application asked to export without any bytes being
 * produced.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class RecordedExport
{
    /**
     * Create a new recorded export entry.
     *
     * @param  string  $type
     * @param  string  $format
     * @param  string|null  $filename
     * @param  string|null  $resolvedFilename
     * @param  string|null  $disk
     * @param  string|null  $path
     * @param  int|null  $rows
     * @param  \SineMacula\Exporter\Export\ExportSpecification|null  $specification
     */
    public function __construct(

        /** The terminal verb that produced the record. */
        public string $type,

        /** The negotiated export format name. */
        public string $format,

        /** The download filename hint, without extension, if any. */
        public ?string $filename = null,

        /** The resolved, extension-bearing download filename, if any. */
        public ?string $resolvedFilename = null,

        /** The target storage disk for a stored export, if any. */
        public ?string $disk = null,

        /** The destination path for a stored or queued export, if any. */
        public ?string $path = null,

        /** The number of source rows the export would emit, if counted. */
        public ?int $rows = null,

        /** The serializable specification for a queued export, if any. */
        public ?ExportSpecification $specification = null,
    ) {}
}
