<?php

declare(strict_types = 1);

namespace SineMacula\Exporter;

use Illuminate\Http\Request;
use SineMacula\Exporter\Contracts\Sink;
use SineMacula\Exporter\Contracts\Source;
use SineMacula\Exporter\Contracts\Writer;
use SineMacula\Exporter\Schema\CastRegistry;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\Contracts\CastRegistry as CastRegistryContract;
use SineMacula\Exporter\Schema\TabularSchema;

/**
 * Tabular export engine.
 *
 * Ties a source, a tabular schema, a writer, and a sink together: it applies
 * the schema's eager-load hints to the source, drops columns the request gates
 * out, shapes each domain item into a row of typed cells through the schema,
 * and streams that lazy row sequence into the writer so the heading row and
 * data rows are emitted at constant memory.
 *
 * The engine is request-explicit - the request is passed in rather than
 * resolved from the container - and holds no per-request state, so it is safe
 * to reuse across exports under Octane.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class Engine
{
    /**
     * Create a new export engine.
     *
     * @param  \SineMacula\Exporter\Schema\Contracts\CastRegistry  $registry
     */
    public function __construct(

        /** The shared, stateless cast registry. */
        private CastRegistryContract $registry = new CastRegistry,
    ) {}

    /**
     * Stream the source through the schema into the writer and sink.
     *
     * @param  \SineMacula\Exporter\Contracts\Source  $source
     * @param  \SineMacula\Exporter\Schema\TabularSchema  $schema
     * @param  \Illuminate\Http\Request  $request
     * @param  \SineMacula\Exporter\Contracts\Writer  $writer
     * @param  \SineMacula\Exporter\Contracts\Sink  $sink
     * @return void
     */
    public function export(Source $source, TabularSchema $schema, Request $request, Writer $writer, Sink $sink): void
    {
        $source  = $source->withRelations($schema->with());
        $columns = $this->visibleColumns($schema, $request);

        $writer->write($this->shape($source, $columns, $request), $schema, $sink);
    }

    /**
     * Resolve the columns visible for the given request, in declaration order.
     *
     * @param  \SineMacula\Exporter\Schema\TabularSchema  $schema
     * @param  \Illuminate\Http\Request  $request
     * @return list<\SineMacula\Exporter\Schema\Column>
     */
    private function visibleColumns(TabularSchema $schema, Request $request): array
    {
        return array_values(array_filter(
            $schema->columns(),
            static fn (Column $column): bool => $column->isVisible($request),
        ));
    }

    /**
     * Shape the source items into a lazy stream of typed-cell rows.
     *
     * @param  \SineMacula\Exporter\Contracts\Source  $source
     * @param  list<\SineMacula\Exporter\Schema\Column>  $columns
     * @param  \Illuminate\Http\Request  $request
     * @return \Generator<int, array<string, \SineMacula\Exporter\Schema\CellValue>>
     */
    private function shape(Source $source, array $columns, Request $request): \Generator
    {
        foreach ($source->rows() as $item) {

            $row = [];

            foreach ($columns as $column) {
                $row[$column->getKey()] = $column->toCellValue($item, $request, $this->registry);
            }

            yield $row;
        }
    }
}
