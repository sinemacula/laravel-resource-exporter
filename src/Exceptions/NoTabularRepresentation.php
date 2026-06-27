<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;

/**
 * No tabular representation exception.
 *
 * Thrown during preflight when a tabular format is negotiated for a resource
 * that does not provide a tabular schema. Maps to HTTP 406 Not Acceptable - the
 * server cannot produce the requested representation - never a silent JSON
 * fallback.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class NoTabularRepresentation extends NotAcceptableHttpException
{
    /**
     * Create an exception for a resource that cannot be represented tabularly.
     *
     * @param  string  $resource
     * @return self
     */
    public static function forResource(string $resource): self
    {
        return new self("Resource [{$resource}] has no tabular representation.");
    }
}
