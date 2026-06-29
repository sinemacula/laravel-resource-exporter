<?php

declare(strict_types = 1);

namespace Benchmarks\Support\Rows;

/**
 * Builds representative row datasets for the exporter benchmarks.
 *
 * Produces a list of associative rows with a handful of mixed scalar columns so
 * the exporter benches exercise the real filter, escape and serialize paths.
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
     * @return list<array<string, bool|float|int|string|null>>
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

    /**
     * Build parent rows with a fixed number of child order rows.
     *
     * @param  int  $parents
     * @param  int  $children
     * @return list<array<string, int|list<array<string, float|int|string>>|string>>
     */
    public static function makeExpanded(int $parents, int $children): array
    {
        $rows = [];

        for ($index = 1; $index <= $parents; $index++) {

            $orders = [];

            for ($order = 1; $order <= $children; $order++) {
                $orders[] = [
                    'sku'          => 'SKU-' . $index . '-' . $order,
                    'quantity'     => $order,
                    'total'        => ($index * $order) + 0.99,
                    'purchased_at' => '2026-02-' . str_pad((string) (($order % 28) + 1), 2, '0', STR_PAD_LEFT),
                ];
            }

            $rows[] = [
                'id'         => $index,
                'first_name' => 'Forename' . $index,
                'last_name'  => 'Surname' . $index,
                'orders'     => $orders,
            ];
        }

        return $rows;
    }

    /**
     * Lazily yield nested rows for hierarchical writer benchmarks.
     *
     * @param  int  $count
     * @return \Generator<int, array<string, array<string, bool|float|int|string>|bool|float|int|list<string>|string>>
     */
    public static function hierarchical(int $count): \Generator
    {
        for ($index = 1; $index <= $count; $index++) {
            yield [
                'id'      => $index,
                'name'    => 'User ' . $index,
                'email'   => 'user' . $index . '@example.com',
                'active'  => $index % 2 === 0,
                'tags'    => ['alpha', 'beta', 'gamma'],
                'profile' => [
                    'score'      => $index + 0.5,
                    'department' => 'Dept ' . (($index % 10) + 1),
                ],
            ];
        }
    }
}
