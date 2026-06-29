<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Contracts;

/**
 * Marker interface for every exception the exporter throws.
 *
 * The package's exceptions extend a range of SPL and HTTP base classes, so a
 * consumer cannot catch them all by a shared parent. Implementing this empty
 * marker on each one lets calling code catch every exporter failure with a
 * single `catch (ExporterException)` while the individual classes keep the base
 * type their semantics require.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface ExporterException {}
