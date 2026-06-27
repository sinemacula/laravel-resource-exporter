<?php

declare(strict_types = 1);

namespace Tests\Support\V3\Schema;

use Illuminate\Http\Request;
use SineMacula\Exporter\Schema\TabularSchema;

/**
 * Configurable tabular schema for writer golden-output tests.
 *
 * Wraps an explicit list of columns plus the heading and filename hints so a
 * writer can be exercised in isolation against a deterministic shape.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class ArraySchema extends TabularSchema
{
    /**
     * Create a new configurable schema.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  list<\SineMacula\Exporter\Schema\Column>  $columns
     * @param  bool  $headings
     * @param  string|null  $filename
     */
    public function __construct(

        // The request the schema is built for.
        Request $request,

        /** @var list<\SineMacula\Exporter\Schema\Column> The ordered columns */
        private readonly array $columns,

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
