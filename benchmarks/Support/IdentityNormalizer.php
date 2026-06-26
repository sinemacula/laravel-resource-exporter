<?php

declare(strict_types = 1);

namespace Benchmarks\Support;

use SineMacula\Foundation\Normalizers\Contracts\NormalizerInterface;

/**
 * Identity normalizer fixture.
 *
 * Returns the value unchanged so registry benches measure pure dispatch
 * overhead.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited
 */
final class IdentityNormalizer implements NormalizerInterface
{
    /**
     * Normalize the given value.
     *
     * @param  mixed  $value
     * @param  mixed|null  $context
     * @return mixed
     */
    #[\Override]
    public static function normalize(mixed $value, mixed $context = null): mixed
    {
        return $value;
    }
}
