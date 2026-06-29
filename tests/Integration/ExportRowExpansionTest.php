<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Engine;
use SineMacula\Exporter\ExportBuilder;
use SineMacula\Exporter\Facades\Exporter;
use SineMacula\Exporter\Schema\ExpandPolicy;
use Tests\Support\ExporterTestCase;
use Tests\Support\Models\User;
use Tests\Support\Resources\UserResource;
use Tests\Support\Schema\ExpandedOrdersExportSchema;

/**
 * Integration tests for row expansion over real Eloquent relations.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Engine::class)]
#[CoversClass(ExportBuilder::class)]
#[CoversClass(ExpandPolicy::class)]
final class ExportRowExpansionTest extends ExporterTestCase
{
    /**
     * It fans out parent rows over a real has-many relation while repeating the
     * parent cells on every child row.
     *
     * @return void
     */
    public function testQueryExportExpandsRowsOverEloquentRelations(): void
    {
        $this->seedUsers(2);
        $this->seedOrders(1, [5, 10]);

        $csv = Exporter::export(User::query()->orderBy('id'), UserResource::class) // @phpstan-ignore staticMethod.dynamicCall
            ->schema(ExpandedOrdersExportSchema::class)
            ->request(Request::create('/'))
            ->chunk(1)
            ->toString();

        self::assertSame([
            ['ID', 'Name', 'SKU', 'Total'],
            ['1', 'User 1', 'SKU-1-1', '5'],
            ['1', 'User 1', 'SKU-1-2', '10'],
            ['2', 'User 2', '', ''],
        ], $this->readCsv($csv));
    }

    /**
     * Read CSV bytes back into parsed rows.
     *
     * @param  string  $contents
     * @return list<list<string|null>>
     */
    private function readCsv(string $contents): array
    {
        $lines = array_filter(explode("\n", trim($contents)), static fn (string $line): bool => $line !== '');

        return array_values(array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), $lines));
    }
}
