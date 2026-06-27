<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Schema;

use Illuminate\Http\Request;
use SineMacula\Exporter\Schema\Concerns\HasAggregates;
use SineMacula\Exporter\Schema\Concerns\HasCasts;
use SineMacula\Exporter\Schema\Contracts\CastRegistry;
use SineMacula\Exporter\Schema\Enums\CellType;

/**
 * Tabular column.
 *
 * A fluent declaration of one column: its source key, optional heading, value
 * resolution, typed cast, display formatting, null/default policy, column-level
 * visibility gate, has-many aggregate, and the single row-expansion opt-in.
 *
 * The per-cell pipeline is: resolve (request-aware accessor unless overridden)
 * -> cast (typed CellValue) -> format -> null/default policy. A null resolved
 * value short-circuits to the default (or an empty cell), since neither a cast
 * nor a formatter can meaningfully act on null. Stateless and request-explicit.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class Column
{
    use HasAggregates;
    use HasCasts;

    /** @var (\Closure(array<array-key, mixed>|object, \Illuminate\Http\Request): mixed)|null The value-source resolver */
    private ?\Closure $resolver = null;

    /** @var (\Closure(mixed, \Illuminate\Http\Request): mixed)|null The post-cast display formatter */
    private ?\Closure $formatter = null;

    /** @var (\Closure(\Illuminate\Http\Request): mixed)|null The column-existence visibility gate */
    private ?\Closure $visibility = null;

    /** @var string|null The raw model path (request-aware accessor opt-out) */
    private ?string $modelPath = null;

    /** @var string|null The value rendered when the resolved value is null */
    private ?string $default = null;

    /**
     * Create a new column.
     *
     * @param  string  $key
     * @param  string|null  $heading
     */
    private function __construct(

        /** The source key the column resolves against. */
        private readonly string $key,

        /** The explicit heading, or null to humanise the key. */
        private ?string $heading = null,
    ) {}

    /**
     * Make a new column for the given source key.
     *
     * @param  string  $key
     * @param  string|null  $heading
     * @return self
     */
    public static function make(string $key, ?string $heading = null): self
    {
        return new self($key, $heading);
    }

    /**
     * Get the source key for the column.
     *
     * @return string
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Get the explicit heading for the column, if set.
     *
     * @return string|null
     */
    public function getHeading(): ?string
    {
        return $this->heading;
    }

    /**
     * Resolve the column value with the given callback (the value source).
     *
     * @param  \Closure(array<array-key, mixed>|object, \Illuminate\Http\Request): mixed  $callback
     * @return static
     */
    public function resolveUsing(\Closure $callback): static
    {
        $this->resolver = $callback;

        return $this;
    }

    /**
     * Transform the column value for display, after casting.
     *
     * @param  \Closure(mixed, \Illuminate\Http\Request): mixed  $callback
     * @return static
     */
    public function formatUsing(\Closure $callback): static
    {
        $this->formatter = $callback;

        return $this;
    }

    /**
     * Resolve the value from a raw model path rather than the request-aware
     * accessor (security opt-out).
     *
     * @param  string  $path
     * @return static
     */
    public function fromModel(string $path): static
    {
        $this->modelPath = $path;

        return $this;
    }

    /**
     * Set the value rendered when the resolved value is null.
     *
     * @param  string  $value
     * @return static
     */
    public function default(string $value): static
    {
        $this->default = $value;

        return $this;
    }

    /**
     * Gate the column's existence with the given callback, evaluated once.
     *
     * @param  \Closure(\Illuminate\Http\Request): mixed  $callback
     * @return static
     */
    public function visible(\Closure $callback): static
    {
        $this->visibility = $callback;

        return $this;
    }

    /**
     * Determine whether the value is read from a raw model path.
     *
     * @return bool
     */
    public function usesModelSource(): bool
    {
        return $this->modelPath !== null;
    }

    /**
     * Get the raw model path, if the request-aware accessor is opted out.
     *
     * @return string|null
     */
    public function getModelPath(): ?string
    {
        return $this->modelPath;
    }

    /**
     * Determine whether the column is visible for the given request.
     *
     * The gate is column existence only (no item), evaluated once at build.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    public function isVisible(Request $request): bool
    {
        return $this->visibility === null
            || (bool) ($this->visibility)($request);
    }

    /**
     * Run the per-cell pipeline for the given item and produce a typed cell.
     *
     * @param  array<array-key, mixed>|object  $item
     * @param  \Illuminate\Http\Request  $request
     * @param  \SineMacula\Exporter\Schema\Contracts\CastRegistry  $registry
     * @return \SineMacula\Exporter\Schema\CellValue
     */
    public function toCellValue(array|object $item, Request $request, CastRegistry $registry): CellValue
    {
        $raw = $this->resolveValue($item, $request);

        if ($raw === null) {
            return $this->nullCell();
        }

        $cell = $this->castValue($raw, $registry);

        if ($this->formatter === null) {
            return $cell;
        }

        return $this->formatCell($cell, $this->formatter, $request);
    }

    /**
     * Run the per-cell pipeline against a single expansion child.
     *
     * Used by the row-expansion axis: the column resolves its value from the
     * given child (a resolver receives the child, otherwise the key or model
     * path is read off it) rather than the parent item, so each child yields
     * its own cell while the parent columns repeat. A null child - a parent
     * with no children kept as a blank row - short-circuits to the
     * null/default cell.
     *
     * @param  mixed  $child
     * @param  \Illuminate\Http\Request  $request
     * @param  \SineMacula\Exporter\Schema\Contracts\CastRegistry  $registry
     * @return \SineMacula\Exporter\Schema\CellValue
     */
    public function toChildCellValue(mixed $child, Request $request, CastRegistry $registry): CellValue
    {
        $raw = $child === null ? null : $this->resolveChildValue($child, $request);

        if ($raw === null) {
            return $this->nullCell();
        }

        $cell = $this->castValue($raw, $registry);

        if ($this->formatter === null) {
            return $cell;
        }

        return $this->formatCell($cell, $this->formatter, $request);
    }

    /**
     * Resolve the raw value source for the column.
     *
     * Precedence: aggregate marker, then an explicit resolver closure, then a
     * raw model path, then the request-aware accessor (the default).
     *
     * @param  array<array-key, mixed>|object  $item
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    private function resolveValue(array|object $item, Request $request): mixed
    {
        if ($this->aggregate !== null) {
            return $this->resolveAggregate($item);
        }

        if ($this->resolver !== null) {
            return ($this->resolver)($item, $request);
        }

        return data_get($item, $this->modelPath ?? $this->key);
    }

    /**
     * Resolve the raw value source for the column from an expansion child.
     *
     * A resolver receives the child directly; otherwise the model path (or, by
     * default, the column key) is read off the child. An aggregate marker is
     * irrelevant here - an expanded column reads each child, not a folded
     * relation - so it is not consulted.
     *
     * @param  mixed  $child
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    private function resolveChildValue(mixed $child, Request $request): mixed
    {
        if ($this->resolver !== null) {
            return ($this->resolver)($child, $request);
        }

        return data_get($child, $this->modelPath ?? $this->key);
    }

    /**
     * Apply the display formatter to a cast cell.
     *
     * The formatter is a display transform, so its result is a plain string
     * cell; a null result falls back to the null/default policy.
     *
     * @param  \SineMacula\Exporter\Schema\CellValue  $cell
     * @param  \Closure(mixed, \Illuminate\Http\Request): mixed  $formatter
     * @param  \Illuminate\Http\Request  $request
     * @return \SineMacula\Exporter\Schema\CellValue
     */
    private function formatCell(CellValue $cell, \Closure $formatter, Request $request): CellValue
    {
        $formatted = $formatter($cell->raw, $request);

        if ($formatted === null) {
            return $this->nullCell();
        }

        return new CellValue($this->stringify($formatted), CellType::STRING);
    }

    /**
     * Coerce a formatter result into a display string.
     *
     * @param  mixed  $value
     * @return string
     */
    private function stringify(mixed $value): string
    {
        return is_scalar($value) || $value instanceof \Stringable
            ? (string) $value
            : '';
    }

    /**
     * Build the cell emitted when the resolved value is null.
     *
     * @return \SineMacula\Exporter\Schema\CellValue
     */
    private function nullCell(): CellValue
    {
        return $this->default !== null
            ? new CellValue($this->default, CellType::STRING)
            : new CellValue(null, CellType::NULL);
    }
}
