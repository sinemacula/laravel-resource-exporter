<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Engine;
use SineMacula\Exporter\ExportBuilder;
use SineMacula\Exporter\ExportManager;
use SineMacula\Exporter\Http\ExportNegotiator;
use Tests\Support\V3\ArraySource;
use Tests\Support\V3\ExporterTestCase;
use Tests\Support\V3\Models\User;
use Tests\Support\V3\Resources\UserResource;
use Tests\Support\V3\Schema\GatedSchema;

/**
 * Octane statelessness guarantees for the long-lived export singletons.
 *
 * Proves the export machinery carries no per-request state between exports, the
 * failure mode a long-lived Octane worker exposes. The same ExportManager
 * singleton and the same container-resolved ExportNegotiator (and the Engine
 * and cast registry inside it) are reused across exports whose request and auth
 * context differ, and each export reflects only its own request: a column the
 * request gates appears for the privileged request and is absent for the
 * unprivileged one, in either order, with no export leaking into the next. No
 * request is captured in a writer or driver; the request is passed in.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExportManager::class)]
#[CoversClass(ExportBuilder::class)]
#[CoversClass(ExportNegotiator::class)]
#[CoversClass(Engine::class)]
final class OctaneStatelessnessTest extends ExporterTestCase
{
    /**
     * The export manager is a single long-lived container singleton.
     *
     * @return void
     */
    public function testManagerResolvesToTheSameSingleton(): void
    {
        $app = $this->app;

        self::assertInstanceOf(Application::class, $app);

        $manager = $app->make(ExportManager::class);

        self::assertInstanceOf(ExportManager::class, $manager);
        self::assertSame($manager, $app->make(ExportManager::class));
    }

    /**
     * Two exports through the same manager singleton do not bleed state.
     *
     * @return void
     */
    public function testManagerSingletonDoesNotBleedRequestStateBetweenExports(): void
    {
        $this->seedUsers(1);

        $app = $this->app;

        self::assertInstanceOf(Application::class, $app);

        /** @var \SineMacula\Exporter\ExportManager $manager */
        $manager = $app->make(ExportManager::class);

        $privileged      = $this->exportThroughManager($manager, $this->requestFor(true));
        $unprivileged    = $this->exportThroughManager($manager, $this->requestFor(false));
        $privilegedAgain = $this->exportThroughManager($manager, $this->requestFor(true));

        self::assertStringContainsString('secret-1', $privileged, 'The privileged request must see the gated value.');
        self::assertStringNotContainsString('secret-1', $unprivileged, 'The unprivileged request must never see the gated value.');
        self::assertStringNotContainsString('Secret', $unprivileged, 'The gated column must be absent entirely for the unprivileged request.');
        self::assertStringContainsString('secret-1', $privilegedAgain, 'The privileged export must still leak after an unprivileged export ran through the same singleton.');
    }

    /**
     * One reused negotiator streams differing request contexts without bleed.
     *
     * @return void
     */
    public function testSharedNegotiatorHoldsNoRequestStateAcrossStreams(): void
    {
        $app = $this->app;

        self::assertInstanceOf(Application::class, $app);

        /** @var \SineMacula\Exporter\Http\ExportNegotiator $negotiator */
        $negotiator = $app->make(ExportNegotiator::class);

        self::assertSame($negotiator, $app->make(ExportNegotiator::class));

        $privileged      = $this->streamThroughNegotiator($negotiator, $this->requestFor(true));
        $unprivileged    = $this->streamThroughNegotiator($negotiator, $this->requestFor(false));
        $privilegedAgain = $this->streamThroughNegotiator($negotiator, $this->requestFor(true));

        self::assertStringContainsString('secret-1', $privileged);
        self::assertStringNotContainsString('secret-1', $unprivileged);
        self::assertStringContainsString('secret-1', $privilegedAgain, 'The shared negotiator must not carry the prior request into the next stream.');
    }

    /**
     * Run a gated CSV export of the seeded users through the manager builder.
     *
     * @param  \SineMacula\Exporter\ExportManager  $manager
     * @param  \Illuminate\Http\Request  $request
     * @return string
     */
    private function exportThroughManager(ExportManager $manager, Request $request): string
    {
        return $manager->export(User::query(), UserResource::class)
            ->format('csv')
            ->schema(GatedSchema::class)
            ->request($request)
            ->toString();
    }

    /**
     * Stream a gated CSV export of one in-memory row through the negotiator.
     *
     * @param  \SineMacula\Exporter\Http\ExportNegotiator  $negotiator
     * @param  \Illuminate\Http\Request  $request
     * @return string
     */
    private function streamThroughNegotiator(ExportNegotiator $negotiator, Request $request): string
    {
        $source = new ArraySource([['id' => 1, 'name' => 'User 1', 'secret' => 'secret-1']]);

        return $this->streamToString($negotiator->streamExport($source, new GatedSchema($request), 'csv', $request));
    }

    /**
     * Build a CSV export request carrying the given privilege flag.
     *
     * @param  bool  $privileged
     * @return \Illuminate\Http\Request
     */
    private function requestFor(bool $privileged): Request
    {
        $request = Request::create('/users', 'GET', server: ['HTTP_ACCEPT' => 'text/csv']);
        $request->attributes->set('is_admin', $privileged);

        return $request;
    }
}
