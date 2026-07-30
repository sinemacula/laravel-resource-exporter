<?php

declare(strict_types = 1);

namespace Tests\Unit\Export;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Events\ExportCompleted;
use SineMacula\Exporter\Export\ExportAuditor;
use Tests\Support\ExporterTestCase;
use Tests\Support\Models\Actor;

/**
 * Unit tests for the shared full-set authorization and audit collaborator.
 *
 * The auditor is the single implementation both the synchronous streamed export
 * and the queued-to-disk pipeline route through, so its authorization and audit
 * behaviour is proven once here.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExportAuditor::class)]
final class ExportAuditorTest extends ExporterTestCase
{
    /**
     * It runs the caller-supplied authorization callback (the streamed path's
     * fluent re-check).
     *
     * @return void
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function testAuthorizeRunsTheCallback(): void
    {
        $ran = false;

        (new ExportAuditor)->authorize(callback: static function () use (&$ran): void {
            $ran = true;
        });

        self::assertTrue($ran);
    }

    /**
     * It throws when the caller-supplied authorization callback denies access.
     *
     * @return void
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function testAuthorizeThrowsWhenTheCallbackReturnsFalse(): void
    {
        $this->expectException(AuthorizationException::class);

        (new ExportAuditor)->authorize(callback: static fn (): bool => false);
    }

    /**
     * It lets a granted ability through the gate (the queued path's
     * serializable re-check).
     *
     * @return void
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function testAuthorizeAllowsAGrantedAbility(): void
    {
        Gate::define('export-set', static fn (Actor $actor): bool => $actor->name === 'admin');

        $actor = new Actor;

        $actor->name = 'admin';

        // The behaviour under test is that authorize() returns without throwing
        // for a granted ability - the no-throw counterpart to the denied case -
        // not that the gate setup itself allows the ability.
        (new ExportAuditor)->authorize(actor: $actor, ability: 'export-set');

        $this->addToAssertionCount(1);
    }

    /**
     * It throws when the gate denies the ability for the actor.
     *
     * @return void
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function testAuthorizeThrowsOnADeniedAbility(): void
    {
        Gate::define('export-set', static fn (Actor $actor): bool => $actor->name === 'admin');

        $actor = new Actor;

        $actor->name = 'mortal';

        $this->expectException(AuthorizationException::class);

        (new ExportAuditor)->authorize(actor: $actor, ability: 'export-set');
    }

    /**
     * It is a no-op when neither a callback nor an ability is given.
     *
     * @return void
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function testAuthorizeIsANoOpWithoutCallbackOrAbility(): void
    {
        (new ExportAuditor)->authorize();

        $this->addToAssertionCount(1);
    }

    /**
     * It fires the ExportCompleted audit event carrying the pinned payload.
     *
     * @return void
     */
    public function testCompletedFiresExportCompletedWithThePinnedPayload(): void
    {
        Event::fake();

        (new ExportAuditor)->completed(new ExportCompleted(
            42,
            7,
            'users',
            'csv',
            now(),
            'exports',
            'exports/users.csv',
            'https://signed.example/exports/users.csv',
            queued: true,
        ));

        Event::assertDispatched(
            ExportCompleted::class,
            static fn (ExportCompleted $event): bool => $event->actorId === 42
                && $event->rowCount                                     === 7
                && $event->filename                                     === 'users'
                && $event->format                                       === 'csv'
                && $event->completedAt->toDateString()                  === now()->toDateString()
                && $event->disk                                         === 'exports'
                && $event->path                                         === 'exports/users.csv'
                && $event->url                                          === 'https://signed.example/exports/users.csv'
                && $event->queued                                       === true,
        );
    }

    /**
     * It defaults the queued discriminator to false for the synchronous
     * streamed path, which passes no queued flag.
     *
     * @return void
     */
    public function testCompletedDefaultsTheQueuedFlagToFalse(): void
    {
        Event::fake();

        (new ExportAuditor)->completed(new ExportCompleted(1, 2, 'users', 'csv', now()));

        Event::assertDispatched(
            ExportCompleted::class,
            static fn (ExportCompleted $event): bool => $event->queued === false,
        );
    }

    /**
     * It routes the audit payload to the configured log channel.
     *
     * @return void
     */
    public function testCompletedRoutesToTheConfiguredLogChannel(): void
    {
        Config::set('exporter.audit.channel', 'export-audit');
        Event::fake();

        Log::shouldReceive('channel')->once()->with('export-audit')->andReturnSelf();
        Log::shouldReceive('info')->once()->with(
            'Resource export completed.',
            \Mockery::on(static fn (array $context): bool => $context['actor_id'] === 7
                && $context['row_count']                                          === 3
                && $context['filename']                                           === 'users'
                && $context['format']                                             === 'csv'
                && is_string($context['completed_at'])),
        );

        (new ExportAuditor)->completed(new ExportCompleted(7, 3, 'users', 'csv', now()));
    }

    /**
     * It does not route anywhere when no log channel is configured.
     *
     * @return void
     */
    public function testCompletedDoesNotRouteWithoutAChannel(): void
    {
        Config::set('exporter.audit.channel', null);
        Event::fake();

        Log::shouldReceive('channel')->never();

        (new ExportAuditor)->completed(new ExportCompleted(null, 1, null, 'csv', now()));

        Event::assertDispatched(ExportCompleted::class);
    }
}
