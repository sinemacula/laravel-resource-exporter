<?php

declare(strict_types = 1);

namespace Tests\Support\V3\Schema;

use Illuminate\Http\Request;
use SineMacula\Exporter\Schema\Enums\Strictness;
use SineMacula\Exporter\Schema\ExpandPolicy;
use SineMacula\Exporter\Schema\TabularSchema;

/**
 * Fully configurable tabular schema for engine behaviour tests.
 *
 * Wraps every overridable facet of a schema - columns, strictness mode, the
 * single expand policy, eager-load hints, heading toggle and filename - behind
 * constructor arguments so an aggregate, row-expansion, strictness or
 * visibility scenario can be expressed inline without a bespoke fixture.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class FlexibleSchema extends TabularSchema
{
    /**
     * Create a new configurable schema.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  list<\SineMacula\Exporter\Schema\Column>  $columns
     * @param  \SineMacula\Exporter\Schema\Enums\Strictness  $strictness
     * @param  \SineMacula\Exporter\Schema\ExpandPolicy|null  $expand
     * @param  list<string>  $with
     * @param  bool  $headings
     * @param  string|null  $filename
     */
    public function __construct(

        // The request the schema is built for.
        Request $request,

        /** @var list<\SineMacula\Exporter\Schema\Column> The ordered columns */
        private readonly array $columns,

        /** The row-level strictness mode. */
        private readonly Strictness $strictness = Strictness::PREFLIGHT,

        /** The single row-expansion policy, if any. */
        private readonly ?ExpandPolicy $expand = null,

        /** @var list<string> The eager-load hints */
        private readonly array $with = [],

        /** Whether a heading row is emitted. */
        private readonly bool $headings = true,

        /** The filename hint for downloads, without extension. */
        private readonly ?string $filename = null,
    ) {
        parent::__construct($request);
    }

    /**
     * Get the ordered columns for the export.
     *
     * @return list<\SineMacula\Exporter\Schema\Column>
     */
    #[\Override]
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * Get the eager-load hints to apply per chunk.
     *
     * @return list<string>
     */
    #[\Override]
    public function with(): array
    {
        return $this->with;
    }

    /**
     * Get the single row-expansion policy, if any.
     *
     * @return \SineMacula\Exporter\Schema\ExpandPolicy|null
     */
    #[\Override]
    public function expand(): ?ExpandPolicy
    {
        return $this->expand;
    }

    /**
     * Get the row-level strictness mode.
     *
     * @return \SineMacula\Exporter\Schema\Enums\Strictness
     */
    #[\Override]
    public function strictness(): Strictness
    {
        return $this->strictness;
    }

    /**
     * Determine whether a heading row is emitted.
     *
     * @return bool
     */
    #[\Override]
    public function headings(): bool
    {
        return $this->headings;
    }

    /**
     * Get the filename hint for downloads, without extension.
     *
     * @return string|null
     */
    #[\Override]
    public function filename(): ?string
    {
        return $this->filename;
    }
}
