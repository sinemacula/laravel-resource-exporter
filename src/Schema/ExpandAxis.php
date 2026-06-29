<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Schema;

/**
 * Resolved row-expansion axis.
 *
 * The validated, ready-to-stream form of a schema's expand policy: the has-many
 * relation whose children fan a parent item out into one row per child, and
 * whether a childless parent is dropped or kept as one row with blank child
 * cells. Exactly one axis exists per export (a single ExpandPolicy relation),
 * so no implicit cartesian product is possible. Immutable and stateless.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final readonly class ExpandAxis
{
    /**
     * Create a new resolved expand axis.
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
