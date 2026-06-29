<?php

declare(strict_types = 1);

namespace SineMacula\Exporter;

use Illuminate\Http\Request;
use SineMacula\Exporter\Contracts\DerivesAggregates;
use SineMacula\Exporter\Contracts\Sink;
use SineMacula\Exporter\Contracts\Source;
use SineMacula\Exporter\Contracts\Writer;
use SineMacula\Exporter\Exceptions\InvalidExportSchema;
use SineMacula\Exporter\Schema\CastRegistry;
use SineMacula\Exporter\Schema\CellValue;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\Contracts\CastRegistry as CastRegistryContract;
use SineMacula\Exporter\Schema\EagerLoadPlan;
use SineMacula\Exporter\Schema\Enums\AggregateType;
use SineMacula\Exporter\Schema\Enums\CellType;
use SineMacula\Exporter\Schema\Enums\Strictness;
use SineMacula\Exporter\Schema\ExpandAxis;
use SineMacula\Exporter\Schema\TabularSchema;
use SineMacula\Exporter\Schema\WarningCollector;

/**
 * Tabular export engine.
 *
 * Ties a source, a tabular schema, a writer, and a sink together: it validates
 * the schema under the strictness mode, applies the schema's eager-load hints
 * and the aggregate plan derived from its columns to the source, drops columns
 * the request gates out, shapes each domain item into a row of typed cells
 * through the schema - fanning a parent out into one row per child along the
 * single row-expansion axis - and streams that lazy row sequence into the
 * writer so the heading row and data rows are emitted at constant memory.
 *
 * Preflight strictness fails fast on an invalid schema before any bytes are
 * sent; lenient strictness skips the offending column or cell and records a
 * warning instead. The engine is request-explicit - the request is passed in
 * rather than resolved from the container - and holds no per-request state, so
 * it is safe to reuse across exports under Octane.
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
     * @param  \SineMacula\Exporter\Schema\WarningCollector|null  $warnings
     * @param  list<string>  $extraRelations
     * @return void
     *
     * @throws \SineMacula\Exporter\Exceptions\InvalidExportSchema
     */
    public function export(
        Source $source,
        TabularSchema $schema,
        Request $request,
        Writer $writer,
        Sink $sink,
        ?WarningCollector $warnings = null,
        array $extraRelations = [],
    ): void {
        $warnings ??= new WarningCollector;
        $strictness = $schema->strictness();

        $columns = $this->compileColumns($this->visibleColumns($schema, $request), $strictness, $warnings);
        $axis    = $this->resolveExpandAxis($columns, $schema, $strictness, $warnings);

        $source = $this->prepareSource($source, $schema, $columns, $axis, $extraRelations);

        $writer->write($this->shape($source, $columns, $axis, $request, $strictness, $warnings), $schema, $sink);
    }

    /**
     * Validate the schema against the request before any bytes are streamed.
     *
     * Compiles the visible columns and resolves the expansion axis under the
     * schema's strictness, so a preflight-strict schema with an invalid column,
     * an unregistered cast, or a broken expand declaration fails fast here -
     * before a streamed response has committed its status - rather than
     * throwing mid-body into an already-sent 200. Lenient strictness degrades
     * instead of throwing, so this is a no-op for it.
     *
     * @param  \SineMacula\Exporter\Schema\TabularSchema  $schema
     * @param  \Illuminate\Http\Request  $request
     * @return void
     *
     * @throws \SineMacula\Exporter\Exceptions\InvalidExportSchema
     */
    public function preflight(TabularSchema $schema, Request $request): void
    {
        $strictness = $schema->strictness();
        $warnings   = new WarningCollector;

        $columns = $this->compileColumns($this->visibleColumns($schema, $request), $strictness, $warnings);

        $this->resolveExpandAxis($columns, $schema, $strictness, $warnings);
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
     * Validate the visible columns under the strictness mode.
     *
     * Preflight throws on the first invalid column before any bytes are sent;
     * lenient drops it and records a warning, so a single bad column degrades
     * the export rather than failing it.
     *
     * @param  list<\SineMacula\Exporter\Schema\Column>  $columns
     * @param  \SineMacula\Exporter\Schema\Enums\Strictness  $strictness
     * @param  \SineMacula\Exporter\Schema\WarningCollector  $warnings
     * @return list<\SineMacula\Exporter\Schema\Column>
     *
     * @throws \SineMacula\Exporter\Exceptions\InvalidExportSchema
     */
    private function compileColumns(array $columns, Strictness $strictness, WarningCollector $warnings): array
    {
        $valid = [];

        foreach ($columns as $column) {

            $problem = $this->columnProblem($column);

            if ($problem === null) {
                $valid[] = $column;

                continue;
            }

            if ($strictness === Strictness::PREFLIGHT) {
                throw InvalidExportSchema::column($column->getKey(), $problem);
            }

            $warnings->add("Column [{$column->getKey()}] skipped: {$problem}.");
        }

        return $valid;
    }

    /**
     * Describe why a column is invalid, or null when it is well-formed.
     *
     * @param  \SineMacula\Exporter\Schema\Column  $column
     * @return string|null
     */
    private function columnProblem(Column $column): ?string
    {
        if (trim($column->getKey()) === '') {
            return 'the column key is empty';
        }

        $cast = $column->getCastName();

        if ($cast !== null && !$this->registry->has($cast)) {
            return "no caster is registered under [{$cast}]";
        }

        return null;
    }

    /**
     * Resolve the single row-expansion axis from the schema and its columns.
     *
     * The schema's expand policy is the source of truth for the relation and
     * the empty-parent behaviour; a column marked expandRows() declares it
     * renders per child. A marked column with no policy (or a policy with no
     * relation) is a misconfiguration: preflight throws, lenient disables
     * expansion with a warning.
     *
     * @param  list<\SineMacula\Exporter\Schema\Column>  $columns
     * @param  \SineMacula\Exporter\Schema\TabularSchema  $schema
     * @param  \SineMacula\Exporter\Schema\Enums\Strictness  $strictness
     * @param  \SineMacula\Exporter\Schema\WarningCollector  $warnings
     * @return \SineMacula\Exporter\Schema\ExpandAxis|null
     *
     * @throws \SineMacula\Exporter\Exceptions\InvalidExportSchema
     */
    private function resolveExpandAxis(array $columns, TabularSchema $schema, Strictness $strictness, WarningCollector $warnings): ?ExpandAxis
    {
        $policy = $schema->expand();

        if ($policy === null) {

            if (array_filter($columns, static fn (Column $column): bool => $column->isExpanded()) !== []) {
                $this->reportExpandProblem('a column is marked expandRows() but the schema declares no expand() relation', $strictness, $warnings);
            }

            return null;
        }

        if (trim($policy->relation) === '') {
            $this->reportExpandProblem('the expand() policy declares an empty relation', $strictness, $warnings);

            return null;
        }

        return new ExpandAxis($policy->relation, $policy->dropWhenEmpty);
    }

    /**
     * Report a row-expansion misconfiguration per the strictness mode.
     *
     * @param  string  $problem
     * @param  \SineMacula\Exporter\Schema\Enums\Strictness  $strictness
     * @param  \SineMacula\Exporter\Schema\WarningCollector  $warnings
     * @return void
     *
     * @throws \SineMacula\Exporter\Exceptions\InvalidExportSchema
     */
    private function reportExpandProblem(string $problem, Strictness $strictness, WarningCollector $warnings): void
    {
        if ($strictness === Strictness::PREFLIGHT) {
            throw InvalidExportSchema::expand($problem);
        }

        $warnings->add("Row expansion disabled: {$problem}.");
    }

    /**
     * Apply the derived eager-loads and aggregate plan to the source.
     *
     * @param  \SineMacula\Exporter\Contracts\Source  $source
     * @param  \SineMacula\Exporter\Schema\TabularSchema  $schema
     * @param  list<\SineMacula\Exporter\Schema\Column>  $columns
     * @param  \SineMacula\Exporter\Schema\ExpandAxis|null  $axis
     * @param  list<string>  $extraRelations
     * @return \SineMacula\Exporter\Contracts\Source
     */
    private function prepareSource(Source $source, TabularSchema $schema, array $columns, ?ExpandAxis $axis, array $extraRelations = []): Source
    {
        $source = $source->withRelations($this->deriveRelations($columns, $schema, $axis, $extraRelations));

        $plan = $this->deriveAggregates($columns);

        if ($source instanceof DerivesAggregates && !$plan->isEmpty()) {
            $source = $source->withAggregates($plan);
        }

        return $source;
    }

    /**
     * Derive the plain eager-load relations from the schema and its columns.
     *
     * The schema's own with() hints are merged with the relation behind each
     * join aggregate (its children are loaded and folded at resolve time) and
     * the row-expansion relation, de-duplicated in declaration order.
     *
     * @param  list<\SineMacula\Exporter\Schema\Column>  $columns
     * @param  \SineMacula\Exporter\Schema\TabularSchema  $schema
     * @param  \SineMacula\Exporter\Schema\ExpandAxis|null  $axis
     * @param  list<string>  $extraRelations
     * @return list<string>
     */
    private function deriveRelations(array $columns, TabularSchema $schema, ?ExpandAxis $axis, array $extraRelations = []): array
    {
        $relations = array_merge($schema->with(), $extraRelations);

        foreach ($columns as $column) {

            $aggregate = $column->getAggregate();

            if ($aggregate === null || $aggregate->type !== AggregateType::JOIN) {
                continue;
            }

            $relations[] = $column->getKey();
        }

        if ($axis !== null) {
            $relations[] = $axis->relation;
        }

        return array_values(array_unique($relations));
    }

    /**
     * Derive the count/sum aggregate plan from the schema's columns.
     *
     * @param  list<\SineMacula\Exporter\Schema\Column>  $columns
     * @return \SineMacula\Exporter\Schema\EagerLoadPlan
     */
    private function deriveAggregates(array $columns): EagerLoadPlan
    {
        $count = [];
        $sum   = [];

        foreach ($columns as $column) {

            $aggregate = $column->getAggregate();

            if ($aggregate === null) {
                continue;
            }

            if ($aggregate->type === AggregateType::COUNT) {
                $count[] = $column->getKey();
            } elseif ($aggregate->type === AggregateType::SUM) {
                $sum[] = ['relation' => $column->getKey(), 'column' => (string) $aggregate->path];
            }
        }

        return new EagerLoadPlan($count, $sum);
    }

    /**
     * Shape the source items into a lazy stream of typed-cell rows.
     *
     * Without an expansion axis each item yields one row; with one, each item
     * fans out into one row per child of the axis relation (its parent cells
     * repeated), or a single blank-child row when the parent has no children
     * and the axis does not drop empties.
     *
     * @param  \SineMacula\Exporter\Contracts\Source  $source
     * @param  list<\SineMacula\Exporter\Schema\Column>  $columns
     * @param  \SineMacula\Exporter\Schema\ExpandAxis|null  $axis
     * @param  \Illuminate\Http\Request  $request
     * @param  \SineMacula\Exporter\Schema\Enums\Strictness  $strictness
     * @param  \SineMacula\Exporter\Schema\WarningCollector  $warnings
     * @return \Generator<int, array<string, \SineMacula\Exporter\Schema\CellValue>>
     */
    private function shape(Source $source, array $columns, ?ExpandAxis $axis, Request $request, Strictness $strictness, WarningCollector $warnings): \Generator
    {
        foreach ($source->rows() as $item) {

            if ($axis === null) {
                yield $this->row($item, $columns, $request, $strictness, $warnings);

                continue;
            }

            yield from $this->expandedRows($item, $axis, $columns, $request, $strictness, $warnings);
        }
    }

    /**
     * Build one shaped row from a single item.
     *
     * @param  array<array-key, mixed>|object  $item
     * @param  list<\SineMacula\Exporter\Schema\Column>  $columns
     * @param  \Illuminate\Http\Request  $request
     * @param  \SineMacula\Exporter\Schema\Enums\Strictness  $strictness
     * @param  \SineMacula\Exporter\Schema\WarningCollector  $warnings
     * @return array<string, \SineMacula\Exporter\Schema\CellValue>
     */
    private function row(array|object $item, array $columns, Request $request, Strictness $strictness, WarningCollector $warnings): array
    {
        $row = [];

        foreach ($columns as $column) {
            $row[$column->getKey()] = $this->cell($column, $item, $request, $strictness, $warnings);
        }

        return $row;
    }

    /**
     * Stream every shaped row for one parent against the expansion axis.
     *
     * The axis children are iterated directly - never copied into an
     * intermediate list - so the fan-out itself adds no memory. The children
     * are, however, eager-loaded with their parent chunk, so peak memory scales
     * with the children per chunk; keep the chunk size and child cardinality
     * bounded for a high-cardinality expansion relation. A parent with children
     * yields one row per child; a childless parent yields a single blank-child
     * row, or no rows when the axis drops empties.
     *
     * @param  array<array-key, mixed>|object  $item
     * @param  \SineMacula\Exporter\Schema\ExpandAxis  $axis
     * @param  list<\SineMacula\Exporter\Schema\Column>  $columns
     * @param  \Illuminate\Http\Request  $request
     * @param  \SineMacula\Exporter\Schema\Enums\Strictness  $strictness
     * @param  \SineMacula\Exporter\Schema\WarningCollector  $warnings
     * @return \Generator<int, array<string, \SineMacula\Exporter\Schema\CellValue>>
     */
    private function expandedRows(array|object $item, ExpandAxis $axis, array $columns, Request $request, Strictness $strictness, WarningCollector $warnings): \Generator
    {
        $children = data_get($item, $axis->relation);
        $expanded = false;

        if (is_iterable($children)) {
            foreach ($children as $child) {
                $expanded = true;

                yield $this->expandedRow($item, $child, $columns, $request, $strictness, $warnings);
            }
        }

        if ($expanded || $axis->dropWhenEmpty) {
            return;
        }

        yield $this->expandedRow($item, null, $columns, $request, $strictness, $warnings);
    }

    /**
     * Build one shaped row of a parent against a single child.
     *
     * The expansion columns render from the child (blank for a childless
     * parent); every other column repeats the parent's cell.
     *
     * @param  array<array-key, mixed>|object  $item
     * @param  mixed  $child
     * @param  list<\SineMacula\Exporter\Schema\Column>  $columns
     * @param  \Illuminate\Http\Request  $request
     * @param  \SineMacula\Exporter\Schema\Enums\Strictness  $strictness
     * @param  \SineMacula\Exporter\Schema\WarningCollector  $warnings
     * @return array<string, \SineMacula\Exporter\Schema\CellValue>
     */
    private function expandedRow(array|object $item, mixed $child, array $columns, Request $request, Strictness $strictness, WarningCollector $warnings): array
    {
        $row = [];

        foreach ($columns as $column) {
            $row[$column->getKey()] = $column->isExpanded()
                ? $this->childCell($column, $child, $request, $strictness, $warnings)
                : $this->cell($column, $item, $request, $strictness, $warnings);
        }

        return $row;
    }

    /**
     * Resolve one cell from a parent item under the strictness mode.
     *
     * @param  \SineMacula\Exporter\Schema\Column  $column
     * @param  array<array-key, mixed>|object  $item
     * @param  \Illuminate\Http\Request  $request
     * @param  \SineMacula\Exporter\Schema\Enums\Strictness  $strictness
     * @param  \SineMacula\Exporter\Schema\WarningCollector  $warnings
     * @return \SineMacula\Exporter\Schema\CellValue
     */
    private function cell(Column $column, array|object $item, Request $request, Strictness $strictness, WarningCollector $warnings): CellValue
    {
        if ($strictness === Strictness::PREFLIGHT) {
            return $column->toCellValue($item, $request, $this->registry);
        }

        try {
            return $column->toCellValue($item, $request, $this->registry);
        } catch (\Throwable $exception) {
            $warnings->add("Column [{$column->getKey()}] blanked: {$exception->getMessage()}.");

            return new CellValue(null, CellType::NULL);
        }
    }

    /**
     * Resolve one cell from an expansion child under the strictness mode.
     *
     * @param  \SineMacula\Exporter\Schema\Column  $column
     * @param  mixed  $child
     * @param  \Illuminate\Http\Request  $request
     * @param  \SineMacula\Exporter\Schema\Enums\Strictness  $strictness
     * @param  \SineMacula\Exporter\Schema\WarningCollector  $warnings
     * @return \SineMacula\Exporter\Schema\CellValue
     */
    private function childCell(Column $column, mixed $child, Request $request, Strictness $strictness, WarningCollector $warnings): CellValue
    {
        if ($strictness === Strictness::PREFLIGHT) {
            return $column->toChildCellValue($child, $request, $this->registry);
        }

        try {
            return $column->toChildCellValue($child, $request, $this->registry);
        } catch (\Throwable $exception) {
            $warnings->add("Column [{$column->getKey()}] blanked: {$exception->getMessage()}.");

            return new CellValue(null, CellType::NULL);
        }
    }
}
