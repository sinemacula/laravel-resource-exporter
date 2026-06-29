<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\ExportBuilder;
use SineMacula\Exporter\ExporterServiceProvider;
use SineMacula\Exporter\ExportManager;
use SineMacula\Exporter\Facades\Exporter as ExporterFacade;
use SineMacula\Exporter\Http\ExportFormat;
use SineMacula\Exporter\Http\ExportNegotiator;
use SineMacula\Exporter\Http\MediaTypeRegistry;
use Tests\Support\ExporterTestCase;
use Tests\Support\Models\User;
use Tests\Support\Resources\UserResource;
use Tests\Support\Writers\ReportWriter;

/**
 * The config('exporter.formats') extension seam is reachable and shared.
 *
 * A custom format declared in configuration is seeded into the single boot-time
 * media type registry, so it is negotiable over the Accept header and reachable
 * through the explicit Exporter::export() builder - both resolve the same
 * shared registry, so they agree on the available formats.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExporterServiceProvider::class)]
#[CoversClass(MediaTypeRegistry::class)]
#[CoversClass(ExportNegotiator::class)]
#[CoversClass(ExportManager::class)]
#[CoversClass(ExportBuilder::class)]
#[CoversClass(ExportFormat::class)]
final class CustomFormatNegotiationTest extends ExporterTestCase
{
    /**
     * The shared registry seeds the config format alongside the built-ins.
     *
     * @return void
     */
    public function testConfigFormatIsSeededIntoTheSharedSingletonRegistry(): void
    {
        $app = $this->app;

        self::assertInstanceOf(Application::class, $app);

        $registry = $app->make(MediaTypeRegistry::class);

        self::assertSame($registry, $app->make(MediaTypeRegistry::class), 'The registry must be a shared singleton.');
        self::assertTrue($registry->has('report'), 'The config-registered format must be present.');
        self::assertTrue($registry->has('bound-report'), 'The container-bound config format must be present.');
        self::assertTrue($registry->has('csv'), 'The built-in formats must remain seeded.');
        self::assertSame('report', $registry->formatForMediaType('application/x-report'));
        self::assertSame('bound-report', $registry->formatForMediaType('application/x-bound-report'));
        self::assertSame('bound-report', $registry->defaultFormat());
    }

    /**
     * A config-registered format is negotiable over the Accept header.
     *
     * @return void
     */
    public function testConfigFormatIsNegotiableOverTheAcceptHeader(): void
    {
        $this->seedUsers(2);

        $response = $this->get('/users', ['Accept' => 'application/x-report']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/x-report; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename=users.report');

        self::assertSame(
            "ID,Name,Email,Active,Joined\n"
            . "1,\"User 1\",user1@example.test,Yes,2026-01-01\n"
            . "2,\"User 2\",user2@example.test,Yes,2026-01-01\n",
            $response->streamedContent(),
        );
    }

    /**
     * The negotiated and explicit export paths agree on the custom format.
     *
     * Both resolve the same shared, config-seeded registry, so the explicit
     * Exporter::export() builder streams the format byte-for-byte the same way
     * the content-negotiated response does.
     *
     * @return void
     */
    public function testNegotiatedAndExplicitPathsAgreeOnTheCustomFormat(): void
    {
        $this->seedUsers(2);

        $negotiated = $this->get('/users', ['Accept' => 'application/x-report'])->streamedContent();

        $explicit = ExporterFacade::export(User::query()->orderBy('id'), UserResource::class) // @phpstan-ignore staticMethod.dynamicCall
            ->format('report')
            ->request(Request::create('/'))
            ->toString();

        self::assertSame($negotiated, $explicit, 'The explicit builder must resolve the same config-registered format as negotiation.');
    }

    /**
     * Register the custom report format through the configuration block.
     *
     * @param  mixed  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        if (!$app instanceof Application) {
            return;
        }

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app->make('config');

        $app->singleton(
            'exporter.test.bound-report-format',
            static fn (): ExportFormat => new ExportFormat(
                'bound-report',
                'bound-report',
                'application/x-bound-report',
                ['application/x-bound-report'],
                true,
                static fn (): ReportWriter => new ReportWriter,
            ),
        );

        $config->set('exporter.negotiation.default_format', 'bound-report');
        $config->set('exporter.formats', [
            42,
            static fn (): ExportFormat => new ExportFormat(
                'report',
                'report',
                'application/x-report',
                ['application/x-report'],
                true,
                static fn (): ReportWriter => new ReportWriter,
            ),
            'exporter.test.bound-report-format',
            'exporter.test.unbound-report-format',
            static fn (): string => 'not-a-format',
        ]);
    }

    /**
     * Define the negotiable user collection route.
     *
     * @param  mixed  $router
     * @return void
     */
    #[\Override]
    protected function defineRoutes(mixed $router): void
    {
        $router->get('/users', static fn (): mixed => UserResource::collection(User::query()->orderBy('id')->get())); // @phpstan-ignore staticMethod.dynamicCall, method.nonObject
    }
}
