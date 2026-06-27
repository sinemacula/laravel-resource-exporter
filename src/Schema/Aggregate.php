<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Schema;

use SineMacula\Exporter\Schema\Enums\AggregateType;

/**
 * Has-many aggregate marker.
 *
 * A declarative record of how a column aggregates a has-many relation. It holds
 * no behaviour: the source reads it to auto-derive the eager-load
 * (`withCount`/`withSum`/`with`) and the column reads it to fold the loaded
 * relation into a single cell. Kept stateless and immutable.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class Aggregate
{
    /**
     * Create a new aggregate marker.
     *
     * @param  \SineMacula\Exporter\Schema\Enums\AggregateType  $type
     * @param  string|null  $path
     * @param  string  $glue
     */
    public function __construct(

        /** The aggregate kind. */
        public AggregateType $type,

        /** The child path summed by a sum aggregate, if any. */
        public ?string $path = null,

        /** The glue used to join children for a join aggregate. */
        public string $glue = ', ',
    ) {}
}
