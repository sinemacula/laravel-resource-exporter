<?php

declare(strict_types = 1);

namespace Tests\Support\Facades;

use Illuminate\Config\Repository;

/**
 * Minimal facade app stub providing config repository access.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 *
 * @implements \ArrayAccess<string, mixed>
 */
final class FacadeAppStub implements \ArrayAccess
{
    /**
     * Create the facade app stub.
     *
     * @param  array<string, mixed>  $config
     */
    public function __construct(

        /** Configuration values for facade reads. */
        private array $config,
    ) {}

    /**
     * Determine whether the given offset exists.
     *
     * @param  string  $offset
     * @return bool
     *
     * @imperative
     */
    #[\Override]
    public function offsetExists(mixed $offset): bool
    {
        return $offset === 'config';
    }

    /**
     * Return the requested offset value.
     *
     * @param  string  $offset
     * @return mixed
     */
    #[\Override]
    public function offsetGet(mixed $offset): mixed
    {
        return $offset === 'config'
            ? new Repository($this->config)
            : null;
    }

    /**
     * Set the offset value.
     *
     * @param  mixed  $offset
     * @param  mixed  $value
     * @return void
     */
    #[\Override]
    public function offsetSet(mixed $offset, mixed $value): void {}

    /**
     * Unset the given offset.
     *
     * @param  string  $offset
     * @return void
     */
    #[\Override]
    public function offsetUnset(mixed $offset): void {}
}
