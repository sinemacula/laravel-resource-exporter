<?php

declare(strict_types = 1);

namespace Tests\Support;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Base test case for resource resolution without a full Laravel application.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
abstract class ResourceTestCase extends TestCase
{
    /**
     * Set up a request-bound container for JsonResource resolution.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container;
        $container->instance('request', Request::create('/'));

        Container::setInstance($container);
    }

    /**
     * Tear down the shared container state.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }
}
