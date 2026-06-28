<?php

declare(strict_types = 1);

namespace Tests\Support\V3\Schema;

use Illuminate\Http\Request;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\TabularSchema;

/**
 * Schema that passes preflight but throws while shaping a row.
 *
 * The columns are structurally valid, so the engine's preflight validation lets
 * the export begin and a staging file is opened; the resolver then throws on
 * the first data row, mid-stream, after bytes have started flowing. It forces a
 * genuine mid-stream failure so the queued job's cleanup - unlinking the
 * staging file and removing any partial disk file - can be asserted.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class ExplodingExportSchema extends TabularSchema
{
    /**
     * Get the ordered columns for the export.
     *
     * @return list<\SineMacula\Exporter\Schema\Column>
     */
    #[\Override]
    public function columns(): array
    {
        return [
            Column::make('id', 'ID'),
            Column::make('boom', 'Boom')
                ->resolveUsing(static fn (mixed $item, Request $request): mixed => self::detonate()),
        ];
    }

    /**
     * Get the filename hint for downloads, without extension.
     *
     * @return string
     */
    #[\Override]
    public function filename(): string
    {
        return 'exploding';
    }

    /**
     * Throw to simulate a value resolver blowing up mid-stream.
     *
     * @return never
     *
     * @throws \RuntimeException
     */
    private static function detonate(): never
    {
        throw new \RuntimeException('exploding column');
    }
}
