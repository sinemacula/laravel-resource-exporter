<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Exceptions;

use SineMacula\Exporter\Contracts\ExporterException;

/**
 * XML export exception.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class XmlExportException extends \RuntimeException implements ExporterException {}
