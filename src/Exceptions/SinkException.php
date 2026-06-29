<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Exceptions;

use SineMacula\Exporter\Contracts\ExporterException;

/**
 * Sink exception.
 *
 * Raised when a sink cannot open, copy, or finalise its underlying byte
 * destination (an unreadable file, an unwritable buffer, or a failed move).
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SinkException extends \RuntimeException implements ExporterException {}
