<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Schema;

/**
 * Row-expansion policy.
 *
 * Describes the single explode axis for an export: the has-many relation whose
 * children become rows, and whether a parent with no children is dropped or
 * kept as one row with blank child cells. Exactly one expand axis is permitted
 * per export (no implicit cartesian products).
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ExpandPolicy
{
    /**
     * Create a new expand policy.
     *
     * @param  string  $relation
     * @param  bool  $dropWhenEmpty
     */
    public function __construct(

        /** The has-many relation whose children become rows. */
        public string $relation,

        /** Whether a childless parent is dropped rather than kept blank. */
        public bool $dropWhenEmpty = false,
    ) {}
}
