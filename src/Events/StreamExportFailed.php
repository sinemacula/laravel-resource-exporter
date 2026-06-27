<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Events;

/**
 * Streamed export failed event.
 *
 * Fired by the HTTP streaming path when an export throws mid-stream, after the
 * 200 status and the first bytes have already been committed to the client. It
 * is the streaming counterpart to the queued-job ExportFailed event: a streamed
 * response cannot retract headers or become a clean error, so the writer
 * flushes a documented truncation marker into the body and the engine fires
 * this event carrying the row context (the format and the number of data rows
 * that reached the client before the failure) plus the actor and the
 * underlying exception, so a listener can alert, audit, or compensate.
 *
 * It is distinct from ExportFailed: there is no disk or path because a streamed
 * export has no stored file, and rowsWritten records exactly how much of the
 * dataset the client received before the stream was truncated.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class StreamExportFailed
{
    /**
     * Create a new streamed export failed event.
     *
     * @param  string  $format
     * @param  int  $rowsWritten
     * @param  int|string|null  $actorId
     * @param  \Throwable  $exception
     */
    public function __construct(

        /** The negotiated export format name. */
        public string $format,

        /** The number of rows streamed to the client before the failure. */
        public int $rowsWritten,

        /** The identifier of the actor who initiated the export, if any. */
        public int|string|null $actorId,

        /** The exception that truncated the stream. */
        public \Throwable $exception,
    ) {}
}
