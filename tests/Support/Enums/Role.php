<?php

declare(strict_types = 1);

namespace Tests\Support\Enums;

/**
 * Backed role enum for enum-cast tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
enum Role: string
{
    case ADMIN = 'admin';
    case USER  = 'user';
}
