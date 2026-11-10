<?php

declare(strict_types = 1);

namespace Tests\Support\Facades;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

/**
 * Application whose config repository holds only the given values, for the
 * facade tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class FacadeAppStub extends Application
{
    /**
     * Create the facade app stub.
     *
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config)
    {
        parent::__construct();

        $this->instance('config', new Repository($config));
    }
}
