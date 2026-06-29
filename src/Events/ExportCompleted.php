<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Events;

use Carbon\CarbonInterface;

/**
 * Export completed audit event.
 *
 * The pinned audit record fired once a full-set or queued export finishes:
 * actor_id, row_count, filename, format, and completed_at form the audit
 * payload required of every full-dataset export. The streamed (synchronous)
 * query export and the queued-to-disk pipeline both fire this same event
 * through the shared auditor, so the audit surface is identical regardless of
 * the front door.
 *
 * The delivery fields - disk, path, and the signed temporary URL - are present
 * only for the queued-to-disk path (a streamed response has no stored file) so
 * a listener can notify the actor where the finished export can be downloaded.
 * The signed URL is a bearer capability that grants download access for its
 * lifetime, so a listener must treat it as a secret - never log it, broadcast
 * it, or persist it beyond the intended recipient. The queued flag
 * discriminates the two front doors directly, so a listener can branch on it
 * without inferring the path from the presence of a disk.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ExportCompleted
{
    /**
     * Create a new export completed audit event.
     *
     * @param  int|string|null  $actorId
     * @param  int  $rowCount
     * @param  string|null  $filename
     * @param  string  $format
     * @param  \Carbon\CarbonInterface  $completedAt
     * @param  string|null  $disk
     * @param  string|null  $path
     * @param  string|null  $url
     * @param  bool  $queued
     */
    public function __construct(

        /** The identifier of the actor who initiated the export, if any. */
        public int|string|null $actorId,

        /** The number of data rows the export emitted. */
        public int $rowCount,

        /** The download filename hint, without extension. */
        public ?string $filename,

        /** The negotiated export format name. */
        public string $format,

        /** The moment the export finished. */
        public CarbonInterface $completedAt,

        /** The storage disk the export was stored on (queued path only). */
        public ?string $disk = null,

        /** The destination path on the disk (queued path only). */
        public ?string $path = null,

        /** The signed temporary download URL (queued path, when supported). */
        public ?string $url = null,

        /** Whether the export ran on a queue worker, not synchronously. */
        public bool $queued = false,
    ) {}
}
