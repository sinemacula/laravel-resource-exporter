<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Export\Concerns;

/**
 * Records replayable query-constraint descriptors for a queued export.
 *
 * The fluent where/whereIn/whereNull/whereNotNull/orderBy/limit/scope surface a
 * queued export exposes, mirroring Eloquent's query builder. Each verb appends
 * a plain array descriptor to the accumulated list rather than touching a live
 * builder, so the whole selection serialises onto a queue and the worker
 * rebuilds the query from the model. The using class owns the terminal verbs
 * (toSpecification/queue) that consume the recorded descriptors.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait BuildsQueryConstraints
{
    /** @var list<array<string, mixed>> The accumulated constraint descriptors */
    private array $constraints = [];

    /**
     * Add a where constraint, defaulting the operator to equality.
     *
     * @param  string  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @return $this
     */
    public function where(string $column, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            $value    = $operator;
            $operator = '=';
        }

        $this->constraints[] = ['type' => 'where', 'column' => $column, 'operator' => $operator ?? '=', 'value' => $value];

        return $this;
    }

    /**
     * Add a where-in constraint.
     *
     * @param  string  $column
     * @param  array<array-key, mixed>  $values
     * @return $this
     */
    public function whereIn(string $column, array $values): static
    {
        $this->constraints[] = ['type' => 'whereIn', 'column' => $column, 'values' => $values];

        return $this;
    }

    /**
     * Add a where-null constraint.
     *
     * @param  string  $column
     * @return $this
     */
    public function whereNull(string $column): static
    {
        $this->constraints[] = ['type' => 'whereNull', 'column' => $column];

        return $this;
    }

    /**
     * Add a where-not-null constraint.
     *
     * @param  string  $column
     * @return $this
     */
    public function whereNotNull(string $column): static
    {
        $this->constraints[] = ['type' => 'whereNotNull', 'column' => $column];

        return $this;
    }

    /**
     * Add an order-by constraint.
     *
     * @param  string  $column
     * @param  string  $direction
     * @return $this
     */
    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $this->constraints[] = ['type' => 'orderBy', 'column' => $column, 'direction' => $direction];

        return $this;
    }

    /**
     * Add a hard limit on the number of records exported.
     *
     * @param  int  $value
     * @return $this
     */
    public function limit(int $value): static
    {
        $this->constraints[] = ['type' => 'limit', 'value' => $value];

        return $this;
    }

    /**
     * Apply a named Eloquent query scope.
     *
     * @param  string  $name
     * @param  mixed  ...$arguments
     * @return $this
     */
    public function scope(string $name, mixed ...$arguments): static
    {
        $this->constraints[] = ['type' => 'scope', 'name' => $name, 'arguments' => array_values($arguments)];

        return $this;
    }
}
