<?php

declare(strict_types = 1);

namespace Tests\Support\Enums;

/**
 * Pure (non-backed) enum for the enum-by-value error path.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
enum Suit
{
    case HEARTS;
    case SPADES;
}
