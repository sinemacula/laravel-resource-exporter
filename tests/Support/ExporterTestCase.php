<?php

declare(strict_types = 1);

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use SineMacula\Exporter\ExporterServiceProvider;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\Models\Order;
use Tests\Support\Models\User;

/**
 * Base test case for negotiation, query and container tests.
 *
 * Boots a real testbench application with an in-memory SQLite database and the
 * users table the source, query and HTTP tests build on.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
abstract class ExporterTestCase extends TestCase
{
    /**
     * Register the package provider for the test application.
     *
     * @param  mixed  $app
     * @return array<int, class-string>
     */
    #[\Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [
            ExporterServiceProvider::class,
        ];
    }

    /**
     * Configure the in-memory database and package defaults.
     *
     * @param  mixed  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        if (!$app instanceof Application) {
            return;
        }

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app->make('config');

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $config->set('exporter.alias', 'exporter');
        $config->set('exporter.default', 'csv');
    }

    /**
     * Create the users table the export tests build on.
     *
     * @return void
     */
    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('email');
            $table->string('role')->default('user');
            $table->integer('score')->default(0);
            $table->boolean('active')->default(true);
            $table->string('secret')->nullable();
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('orders', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->integer('total')->default(0);
            $table->string('sku');
        });
    }

    /**
     * Seed the given number of users with deterministic attributes.
     *
     * @param  int  $count
     * @return void
     */
    protected function seedUsers(int $count): void
    {
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'name'       => 'User ' . $i,
                'email'      => 'user' . $i . '@example.test',
                'role'       => 'user',
                'score'      => $i,
                'active'     => true,
                'secret'     => 'secret-' . $i,
                'created_at' => '2026-01-01 00:00:00',
            ];
        }

        User::query()->insert($rows); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Seed the given order totals against a user, each with a derived SKU.
     *
     * @param  int  $userId
     * @param  list<int>  $totals
     * @return void
     */
    protected function seedOrders(int $userId, array $totals): void
    {
        if ($totals === []) {
            return;
        }

        $rows = [];

        foreach ($totals as $index => $total) {
            $rows[] = [
                'user_id' => $userId,
                'total'   => $total,
                'sku'     => 'SKU-' . $userId . '-' . ($index + 1),
            ];
        }

        Order::query()->insert($rows); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Drain a streamed response body to a string.
     *
     * @param  \Symfony\Component\HttpFoundation\StreamedResponse  $response
     * @return string
     */
    protected function streamToString(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
