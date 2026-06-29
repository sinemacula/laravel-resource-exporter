<?php

declare(strict_types = 1);

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Models\Actor;

/**
 * Base test case for the queued-export pipeline tests.
 *
 * Extends the negotiation/query base with the actors table the queued
 * authorization and audit tests attribute exports to, and a small helper that
 * seeds an Authenticatable actor the queued path can re-resolve on the worker.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
abstract class QueuedExportTestCase extends ExporterTestCase
{
    /**
     * Create the users, orders and actors tables the queued tests build on.
     *
     * @return void
     */
    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::create('actors', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
    }

    /**
     * Seed an actor with the given name and return the persisted model.
     *
     * @param  string  $name
     * @return \Tests\Support\Models\Actor
     */
    protected function seedActor(string $name): Actor
    {
        $actor = new Actor;

        $actor->name = $name;
        $actor->save();

        return $actor;
    }

    /**
     * Fake the given storage disk and return it as a concrete adapter.
     *
     * @param  string  $name
     * @return \Illuminate\Filesystem\FilesystemAdapter
     */
    protected function fakeDisk(string $name): FilesystemAdapter
    {
        Storage::fake($name);

        $disk = Storage::disk($name);

        static::assertInstanceOf(FilesystemAdapter::class, $disk);

        return $disk;
    }

    /**
     * List the queued-export staging files currently in the temp directory.
     *
     * The job stages each attempt to its own tempnam() file under the system
     * temp directory and unlinks it in a finally block; comparing this set
     * before and after a run proves the staging file is cleaned up.
     *
     * @return list<string>
     */
    protected function stagingTempFiles(): array
    {
        $files = glob(sys_get_temp_dir() . '/export_queue_*');

        return $files === false ? [] : $files;
    }
}
