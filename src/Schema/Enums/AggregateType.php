<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Schema\Enums;

/**
 * Has-many aggregate kind.
 *
 * Names the way a column folds a has-many relation into a single cell. Each
 * kind maps to a distinct eager-load derivation the source applies: Count to
 * `withCount`, Sum to `withSum`, and Join to a plain `with` (the children are
 * loaded and joined at resolve time).
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum AggregateType: string
{
    case COUNT = 'count';
    case SUM   = 'sum';
    case JOIN  = 'join';
}
