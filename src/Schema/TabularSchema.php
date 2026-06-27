<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Schema;

use Illuminate\Http\Request;
use SineMacula\Exporter\Schema\Enums\Strictness;

/**
 * Tabular schema.
 *
 * The request-aware declaration of a tabular representation: ordered columns,
 * eager-load hints, an optional single row-expansion axis, a filename hint,
 * and the row-level strictness mode. Authored as a dedicated sibling class so
 * the export shape stays separate from the API representation.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class TabularSchema
{
    /**
     * Create a new tabular schema for the current request.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    public function __construct(

        /** The current request the schema is built for. */
        protected readonly Request $request,
    ) {}

    /**
     * Get the ordered columns for the export.
     *
     * @return list<\SineMacula\Exporter\Schema\Column>
     */
    abstract public function columns(): array;

    /**
     * Get the eager-load hints to apply per chunk.
     *
     * Auto-derived aggregate hints are merged on top of these.
     *
     * @return list<string>
     */
    public function with(): array
    {
        return [];
    }

    /**
     * Get the single row-expansion policy, if any.
     *
     * @return \SineMacula\Exporter\Schema\ExpandPolicy|null
     */
    public function expand(): ?ExpandPolicy
    {
        return null;
    }

    /**
     * Get the filename hint for downloads, without extension.
     *
     * @return string|null
     */
    public function filename(): ?string
    {
        return null;
    }

    /**
     * Get the row-level strictness mode.
     *
     * @return \SineMacula\Exporter\Schema\Enums\Strictness
     */
    public function strictness(): Strictness
    {
        return Strictness::PREFLIGHT;
    }

    /**
     * Determine whether a heading row is emitted.
     *
     * @return bool
     */
    public function headings(): bool
    {
        return true;
    }
}
