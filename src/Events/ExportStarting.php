<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Events;

/**
 * Export starting event.
 *
 * Fired by the queued export pipeline before any rows are read, carrying the
 * negotiated context known up front: the format, the target disk and path, and
 * the identifier of the actor who initiated the export. Listeners can use it to
 * mark a job as in progress or warn an operator a large export has begun.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ExportStarting
{
    /**
     * Create a new export starting event.
     *
     * @param  string  $format
     * @param  string  $disk
     * @param  string  $path
     * @param  int|string|null  $actorId
     */
    public function __construct(

        /** The negotiated export format name. */
        public string $format,

        /** The target storage disk the export is written to. */
        public string $disk,

        /** The destination path on the disk. */
        public string $path,

        /** The identifier of the actor who initiated the export, if any. */
        public int|string|null $actorId = null,
    ) {}
}
