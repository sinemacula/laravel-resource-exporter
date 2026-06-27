<?php

declare(strict_types = 1);

namespace Benchmarks\Support;

/**
 * Builds representative row datasets for the exporter benchmarks.
 *
 * Produces a list of associative rows with a handful of mixed scalar columns
 * so the exporter benches exercise the real filter, escape and serialize paths.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RowFactory
{
    /**
     * Build a list of associative rows with mixed scalar columns.
     *
     * @param  int  $count
     * @return array<int, array<string, bool|float|int|string|null>>
     */
    public static function make(int $count): array
    {
        $rows = [];

        for ($index = 1; $index <= $count; $index++) {
            $rows[] = [
                'id'         => $index,
                'first_name' => 'Forename' . $index,
                'last_name'  => 'Surname' . $index,
                'email'      => 'user' . $index . '@example.com',
                'is_active'  => $index % 2 === 0,
                'balance'    => $index * 1.5,
                'reference'  => $index % 5 === 0 ? null : 'REF-' . $index,
                'created_at' => '2026-01-' . str_pad((string) (($index % 28) + 1), 2, '0', STR_PAD_LEFT),
            ];
        }

        return $rows;
    }
}
