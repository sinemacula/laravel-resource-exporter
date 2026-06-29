<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Exceptions;

use SineMacula\Exporter\Contracts\ExporterException;

/**
 * Missing XLSX dependency exception.
 *
 * Thrown when an XLSX export is requested but the optional openspout/openspout
 * package is not installed. OpenSpout is a suggested, runtime-optional driver,
 * so the writer fails with an actionable message rather than a bare
 * class-not-found error.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class MissingXlsxDependency extends \RuntimeException implements ExporterException
{
    /**
     * Create an exception describing the missing OpenSpout dependency.
     *
     * @return self
     */
    public static function create(): self
    {
        return new self('The XLSX writer requires the openspout/openspout package. Run: composer require openspout/openspout');
    }
}
