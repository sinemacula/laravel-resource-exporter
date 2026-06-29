<?php

declare(strict_types = 1);

namespace Tests\Support\Enums;

/**
 * Integer-backed priority enum for the enum scalar-cast tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
enum Priority: int
{
    case LOW  = 1;
    case HIGH = 3;
}
