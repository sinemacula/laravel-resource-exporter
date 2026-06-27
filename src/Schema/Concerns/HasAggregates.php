<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Schema\Concerns;

use SineMacula\Exporter\Schema\Aggregate;
use SineMacula\Exporter\Schema\Enums\AggregateType;

/**
 * Column aggregate concern.
 *
 * The has-many aggregate vocabulary (count / sum / join), the single
 * row-expansion opt-in, and the resolution of an aggregate marker into a cell
 * value from the eager-loaded item. Kept off the column class to keep its
 * surface focused.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait HasAggregates
{
    /** @var \SineMacula\Exporter\Schema\Aggregate|null The has-many aggregate marker */
    private ?Aggregate $aggregate = null;

    /** @var bool Whether this column is the single row-expansion axis */
    private bool $expand = false;

    /**
     * Aggregate a has-many relation as a count.
     *
     * @return static
     */
    public function count(): static
    {
        $this->aggregate = new Aggregate(AggregateType::COUNT);

        return $this;
    }

    /**
     * Aggregate a has-many relation as a sum over the given path.
     *
     * @param  string  $path
     * @return static
     */
    public function sum(string $path): static
    {
        $this->aggregate = new Aggregate(AggregateType::SUM, $path);

        return $this;
    }

    /**
     * Aggregate a has-many relation by joining its values with the given glue.
     *
     * @param  string  $glue
     * @return static
     */
    public function join(string $glue = ', '): static
    {
        $this->aggregate = new Aggregate(AggregateType::JOIN, glue: $glue);

        return $this;
    }

    /**
     * Explode a has-many relation into one row per child (the single expand
     * axis).
     *
     * @return static
     */
    public function expandRows(): static
    {
        $this->expand = true;

        return $this;
    }

    /**
     * Get the has-many aggregate marker, if any.
     *
     * @return \SineMacula\Exporter\Schema\Aggregate|null
     */
    public function getAggregate(): ?Aggregate
    {
        return $this->aggregate;
    }

    /**
     * Determine whether this column is the row-expansion axis.
     *
     * @return bool
     */
    public function isExpanded(): bool
    {
        return $this->expand;
    }

    /**
     * Resolve the value of a has-many aggregate from the loaded item.
     *
     * @param  array<array-key, mixed>|object  $item
     * @return mixed
     */
    private function resolveAggregate(array|object $item): mixed
    {
        $aggregate = $this->aggregate;

        if ($aggregate === null) {
            return null;
        }

        return match ($aggregate->type) {
            AggregateType::COUNT => $this->intValue(data_get($item, $this->key . '_count')),
            AggregateType::SUM   => data_get($item, $this->key . '_sum_' . str_replace('.', '_', (string) $aggregate->path)),
            AggregateType::JOIN  => $this->joinChildren(data_get($item, $this->key), $aggregate->glue),
        };
    }

    /**
     * Join the scalar representation of each child of a has-many relation.
     *
     * @param  mixed  $children
     * @param  string  $glue
     * @return string
     */
    private function joinChildren(mixed $children, string $glue): string
    {
        if (!is_iterable($children)) {
            return '';
        }

        $parts = [];

        foreach ($children as $child) {

            if (!is_scalar($child) && !$child instanceof \Stringable) {
                continue;
            }

            $parts[] = (string) $child;
        }

        return implode($glue, $parts);
    }

    /**
     * Coerce a loose aggregate value into an integer.
     *
     * @param  mixed  $value
     * @return int
     */
    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
