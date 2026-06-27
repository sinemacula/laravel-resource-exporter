<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Engine;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\TabularSchema;
use SineMacula\Exporter\Sinks\StringSink;
use SineMacula\Exporter\Writers\CsvWriter;
use Tests\Support\V3\ArraySource;
use Tests\Support\V3\Schema\GatedSchema;

/**
 * Tests for the export engine: column gating, the attribute-leak boundary, and
 * eager-load-hint forwarding.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Engine::class)]
final class EngineTest extends TestCase
{
    /**
     * It never emits a value the request gates out (the leak boundary).
     *
     * The gated "secret" column is dropped for an unauthorised request, and the
     * request-aware "redacted" resolver returns null for that request, so the
     * sensitive value cannot reach the export through either path. An
     * authorised request surfaces both.
     *
     * @return void
     */
    public function testGatedValueNeverLeaksForAnUnauthorisedRequest(): void
    {
        $item = ['id' => 1, 'name' => 'Ada', 'secret' => 'TOPSECRET'];

        $guest = $this->exportGated($item, admin: false);

        self::assertStringNotContainsString('TOPSECRET', $guest);
        self::assertStringNotContainsString('Secret', $guest);
        self::assertSame("ID,Name,Redacted\n1,Ada,\n", $guest);

        $admin = $this->exportGated($item, admin: true);

        self::assertStringContainsString('Secret', $admin);
        self::assertStringContainsString('TOPSECRET', $admin);
    }

    /**
     * It forwards the schema's eager-load hints to the source.
     *
     * @return void
     */
    public function testForwardsEagerLoadHintsToTheSource(): void
    {
        $request = Request::create('/');
        $schema  = new class ($request) extends TabularSchema {
            /**
             * Get the ordered columns for the export.
             *
             * @return list<\SineMacula\Exporter\Schema\Column>
             */
            #[\Override]
            public function columns(): array
            {
                return [Column::make('id')];
            }

            /**
             * Get the eager-load hints.
             *
             * @return list<string>
             */
            #[\Override]
            public function with(): array
            {
                return ['profile', 'orders'];
            }
        };

        $source = new ArraySource([['id' => 1]]);

        (new Engine)->export($source, $schema, $request, new CsvWriter, new StringSink);

        self::assertSame(['profile', 'orders'], $source->appliedRelations);
    }

    /**
     * Export a single item through the gated schema as CSV.
     *
     * @param  array<string, mixed>  $item
     * @param  bool  $admin
     * @return string
     */
    private function exportGated(array $item, bool $admin): string
    {
        $request = Request::create('/');
        $request->attributes->set('is_admin', $admin);

        $sink = new StringSink;

        (new Engine)->export(
            new ArraySource([$item]),
            new GatedSchema($request),
            $request,
            new CsvWriter,
            $sink,
        );

        return $sink->contents();
    }
}
