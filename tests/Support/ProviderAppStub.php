<?php

declare(strict_types = 1);

namespace Tests\Support;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

/**
 * Application for the service provider tests, with the given config values and
 * console mode.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class ProviderAppStub extends Application
{
    /**
     * Create the app stub.
     *
     * @param  array<string, mixed>  $config
     * @param  bool  $runningInConsole
     */
    public function __construct(array $config = [], bool $runningInConsole = true)
    {
        parent::__construct();

        $this->instance('config', new Repository($config));

        $this->isRunningInConsole = $runningInConsole;
    }

    /**
     * Return the abstracts registered as shared bindings.
     *
     * @return list<string>
     */
    public function singletonBindings(): array
    {
        return array_keys(array_filter($this->bindings, static fn (array $binding): bool => $binding['shared'] === true));
    }

    /**
     * Return the registered aliases, keyed by alias.
     *
     * @return array<string, string>
     */
    public function aliasBindings(): array
    {
        return $this->aliases;
    }

    /**
     * Return the config repository bound at construction.
     *
     * @return \Illuminate\Config\Repository
     *
     * @throws \LogicException
     */
    public function config(): Repository
    {
        $repository = $this->make('config');

        if (!$repository instanceof Repository) {
            throw new \LogicException('The provider app stub must hold a config repository.');
        }

        return $repository;
    }
}
