<?php

declare(strict_types = 1);

namespace Tests\Support;

use Illuminate\Config\Repository;

/**
 * Minimal app stub for service provider registration tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 *
 * @implements \ArrayAccess<string, mixed>
 */
final class ProviderAppStub implements \ArrayAccess
{
    /** @var array<string, \Closure(mixed): mixed> */
    private array $singletons = [];

    /** @var \Illuminate\Config\Repository */
    private Repository $repository;

    /**
     * Create the app stub.
     *
     * @param  array<string, mixed>  $config
     * @param  bool  $runningInConsole
     */
    public function __construct(

        array $config = [],

        // Whether the app is running in console.
        private bool $runningInConsole = true,

    ) {
        $this->repository = new Repository($config);
    }

    /**
     * Resolve dependencies through a minimal make API.
     *
     * @param  string  $abstract
     * @return mixed
     */
    public function make(string $abstract): mixed
    {
        return match ($abstract) {
            'config' => $this->config(),
            default  => null,
        };
    }

    /**
     * Register a singleton binding callback.
     *
     * @param  string  $abstract
     * @param  \Closure(mixed): mixed  $callback
     * @return void
     */
    public function singleton(string $abstract, \Closure $callback): void
    {
        $this->singletons[$abstract] = $callback;
    }

    /**
     * Determine whether the app is in console mode.
     *
     * @return bool
     */
    public function runningInConsole(): bool
    {
        return $this->runningInConsole;
    }

    /**
     * Return singleton bindings captured by the stub.
     *
     * @return array<string, \Closure(mixed): mixed>
     */
    public function singletonBindings(): array
    {
        return $this->singletons;
    }

    /**
     * Return config repository instance.
     *
     * @return \Illuminate\Config\Repository
     */
    public function config(): Repository
    {
        return $this->repository;
    }

    /**
     * Determine if an offset exists.
     *
     * @param  mixed  $offset
     * @return bool
     */
    #[\Override]
    public function offsetExists(mixed $offset): bool
    {
        return $offset === 'config';
    }

    /**
     * Get an offset value.
     *
     * @param  mixed  $offset
     * @return mixed
     */
    #[\Override]
    public function offsetGet(mixed $offset): mixed
    {
        return $offset === 'config' ? $this->config() : null;
    }

    /**
     * Set an offset value.
     *
     * @param  mixed  $offset
     * @param  mixed  $value
     * @return void
     */
    #[\Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset !== 'config' || !$value instanceof Repository) {
            return;
        }

        $this->repository = $value;
    }

    /**
     * Unset an offset.
     *
     * @param  mixed  $offset
     * @return void
     */
    #[\Override]
    public function offsetUnset(mixed $offset): void
    {
        if ($offset !== 'config') {
            return;
        }

        $this->repository = new Repository([]);
    }
}
