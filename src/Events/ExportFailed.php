<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Events;

/**
 * Export failed event.
 *
 * Fired when a queued export ultimately fails (after the job has exhausted its
 * retries), carrying the format, the target disk and path, the initiating
 * actor, and the underlying exception. The pipeline removes any partially
 * written file before the failure so a retry - and any compensating action a
 * listener takes - starts from a clean slate.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ExportFailed
{
    /**
     * Create a new export failed event.
     *
     * @param  string  $format
     * @param  string  $disk
     * @param  string  $path
     * @param  int|string|null  $actorId
     * @param  \Throwable  $exception
     */
    public function __construct(

        /** The negotiated export format name. */
        public string $format,

        /** The target storage disk the export was being written to. */
        public string $disk,

        /** The destination path on the disk. */
        public string $path,

        /** The identifier of the actor who initiated the export, if any. */
        public int|string|null $actorId,

        /** The exception that caused the export to fail. */
        public \Throwable $exception,
    ) {}
}
