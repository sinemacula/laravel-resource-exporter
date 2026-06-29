<?php

declare(strict_types = 1);

namespace Benchmarks\Support;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

/**
 * In-memory SQLite database fixture for query-source benchmarks.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class BenchmarkDatabase
{
    /**
     * Create and seed an in-memory Eloquent database.
     *
     * @param  int  $users
     * @param  int  $ordersPerUser
     * @return void
     */
    public static function seed(int $users, int $ordersPerUser): void
    {
        $capsule = new Capsule;
        $capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::createSchema($capsule);
        self::seedUsers($users);
        self::seedOrders($users, $ordersPerUser);
    }

    /**
     * Create benchmark tables.
     *
     * @param  \Illuminate\Database\Capsule\Manager  $capsule
     * @return void
     */
    private static function createSchema(Capsule $capsule): void
    {
        $schema = $capsule->schema(); // @phpstan-ignore staticMethod.dynamicCall

        $schema->create('benchmark_users', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->boolean('is_active')->default(true);
            $table->float('balance')->default(0);
            $table->string('reference')->nullable();
            $table->dateTime('created_at')->nullable();
        });

        $schema->create('benchmark_orders', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->integer('quantity')->default(1);
            $table->float('total')->default(0);
            $table->string('sku');
            $table->date('purchased_at');
        });
    }

    /**
     * Seed benchmark users.
     *
     * @param  int  $count
     * @return void
     */
    private static function seedUsers(int $count): void
    {
        $rows = [];

        for ($index = 1; $index <= $count; $index++) {
            $rows[] = [
                'first_name' => 'Forename' . $index,
                'last_name'  => 'Surname' . $index,
                'email'      => 'user' . $index . '@example.com',
                'is_active'  => $index % 2 === 0,
                'balance'    => $index * 1.5,
                'reference'  => $index % 5 === 0 ? null : 'REF-' . $index,
                'created_at' => '2026-01-' . str_pad((string) (($index % 28) + 1), 2, '0', STR_PAD_LEFT),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            BenchmarkUser::query()->insert($chunk); // @phpstan-ignore staticMethod.dynamicCall
        }
    }

    /**
     * Seed benchmark orders.
     *
     * @param  int  $users
     * @param  int  $ordersPerUser
     * @return void
     */
    private static function seedOrders(int $users, int $ordersPerUser): void
    {
        $rows = [];

        for ($user = 1; $user <= $users; $user++) {
            for ($order = 1; $order <= $ordersPerUser; $order++) {
                $rows[] = [
                    'user_id'      => $user,
                    'quantity'     => $order,
                    'total'        => ($user * $order) + 0.99,
                    'sku'          => 'SKU-' . $user . '-' . $order,
                    'purchased_at' => '2026-02-' . str_pad((string) (($order % 28) + 1), 2, '0', STR_PAD_LEFT),
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            BenchmarkOrder::query()->insert($chunk); // @phpstan-ignore staticMethod.dynamicCall
        }
    }
}
