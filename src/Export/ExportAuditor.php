<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Export;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use SineMacula\Exporter\Events\ExportCompleted;

/**
 * Shared full-set authorization and audit collaborator.
 *
 * The single home for the two cross-cutting concerns a full-dataset export
 * carries that a page-scoped response does not: re-checking authorization for
 * the whole set, and emitting the pinned audit record when it completes. Both
 * the synchronous streamed query export (ResourceExport) and the queued-to-disk
 * pipeline (ExportToDiskJob) route through this one class, so the authorization
 * enforcement point and the ExportCompleted audit payload are identical
 * regardless of which front door produced the export.
 *
 * Authorization accepts either a caller-supplied closure (the streamed path's
 * fluent re-check) or an ability resolved against the initiating actor through
 * the gate (the queued path's serializable re-check); either form throws when
 * access is denied. The audit event is always dispatched; routing it to a
 * dedicated log channel is optional and driven by configuration, never gating
 * the export. The class holds no per-request state, so it is safe to share
 * under Octane.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ExportAuditor
{
    /**
     * Re-check authorization for the full dataset, throwing when denied.
     *
     * @param  \Closure(): void|null  $callback
     * @param  \Illuminate\Contracts\Auth\Authenticatable|null  $actor
     * @param  string|null  $ability
     * @param  mixed  $arguments
     * @return void
     */
    public function authorize(?\Closure $callback = null, ?Authenticatable $actor = null, ?string $ability = null, mixed $arguments = []): void
    {
        $callback?->__invoke();

        if ($ability === null) {
            return;
        }

        Gate::forUser($actor)->authorize($ability, $arguments);
    }

    /**
     * Fire the pinned ExportCompleted audit event and optionally log it.
     *
     * @param  int|string|null  $actorId
     * @param  int  $rowCount
     * @param  string|null  $filename
     * @param  string  $format
     * @param  string|null  $disk
     * @param  string|null  $path
     * @param  string|null  $url
     * @return void
     */
    public function completed(int|string|null $actorId, int $rowCount, ?string $filename, string $format, ?string $disk = null, ?string $path = null, ?string $url = null): void
    {
        $event = new ExportCompleted(
            $actorId,
            $rowCount,
            $filename,
            $format,
            CarbonImmutable::now(),
            $disk,
            $path,
            $url,
        );

        Event::dispatch($event);

        $this->route($event);
    }

    /**
     * Route the audit payload to the configured log channel, if any.
     *
     * @param  \SineMacula\Exporter\Events\ExportCompleted  $event
     * @return void
     */
    private function route(ExportCompleted $event): void
    {
        $channel = Config::get('exporter.audit.channel');

        if (!is_string($channel) || $channel === '') {
            return;
        }

        Log::channel($channel)->info('Resource export completed.', [
            'actor_id'     => $event->actorId,
            'row_count'    => $event->rowCount,
            'filename'     => $event->filename,
            'format'       => $event->format,
            'completed_at' => $event->completedAt->toIso8601String(),
        ]);
    }
}
