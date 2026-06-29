<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Export;

use Illuminate\Database\Eloquent\Builder;

/**
 * Serializable queued-export specification.
 *
 * A live query builder or a closure cannot be reliably serialized onto a queue,
 * so a queued export is described instead by this value object of simple
 * scalars: the model and resource class strings, an optional dedicated schema
 * class, the format, the target disk and path, and a list of plain constraint
 * descriptors (where / whereIn / whereNull / orderBy / limit, or a named
 * Eloquent scope) the job replays to rebuild the query on the worker. It also
 * carries the download filename hint, the initiating actor (id plus class, so
 * the worker can resolve and re-authorize against the original user), an
 * optional gate ability for the full-set authorization re-check, and the chunk,
 * progress and signed-URL-expiry tuning. A missing ability only means the
 * caller deliberately waived the full-set check when authorizationWaived is
 * true. Every field is a string, int, bool or array of those, so the whole
 * specification round-trips through the queue cleanly.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ExportSpecification
{
    /**
     * Create a new queued-export specification.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resource
     * @param  class-string<\SineMacula\Exporter\Schema\TabularSchema>|null  $schema
     * @param  string  $format
     * @param  string  $disk
     * @param  string  $path
     * @param  list<array<string, mixed>>  $constraints
     * @param  string|null  $filename
     * @param  int|string|null  $actorId
     * @param  class-string<\Illuminate\Database\Eloquent\Model>|null  $actorClass
     * @param  string|null  $ability
     * @param  int  $chunkSize
     * @param  int  $progressEvery
     * @param  int  $urlExpiresAfter
     * @param  bool  $authorizationWaived
     */
    public function __construct(

        /** @var class-string<\Illuminate\Database\Eloquent\Model> The model the export query runs over. */
        public string $model,

        /** @var class-string<\Illuminate\Http\Resources\Json\JsonResource> The resource class describing the export. */
        public string $resource,

        /** @var class-string<\SineMacula\Exporter\Schema\TabularSchema>|null The dedicated schema class, or null to resolve from the resource. */
        public ?string $schema,

        /** The negotiated export format name. */
        public string $format,

        /** The target storage disk the export is written to. */
        public string $disk,

        /** The destination path on the disk. */
        public string $path,

        /** @var list<array<string, mixed>> The plain constraint descriptors replayed to rebuild the query. */
        public array $constraints = [],

        /** The download filename hint, without extension. */
        public ?string $filename = null,

        /** The identifier of the actor who initiated the export. */
        public int|string|null $actorId = null,

        /** @var class-string<\Illuminate\Database\Eloquent\Model>|null The actor's model class, used to resolve and re-authorize. */
        public ?string $actorClass = null,

        /** The gate ability re-checked against the full set, if any. */
        public ?string $ability = null,

        /** The keyset chunk size used while streaming the full set. */
        public int $chunkSize = 1000,

        /** The number of rows between progress events. */
        public int $progressEvery = 1000,

        /** The lifetime, in minutes, of the signed temporary download URL. */
        public int $urlExpiresAfter = 60,

        /** Whether the full-set authorization requirement was waived. */
        public bool $authorizationWaived = false,
    ) {}

    /**
     * Rebuild the export query by replaying the constraint descriptors.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function query(): Builder
    {
        $query = $this->model::query();

        foreach ($this->constraints as $constraint) {
            $this->apply($query, $constraint);
        }

        return $query;
    }

    /**
     * Apply a single constraint descriptor to the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $constraint
     * @return void
     */
    private function apply(Builder $query, array $constraint): void
    {
        match ($constraint['type'] ?? null) {
            'where'        => $this->where($query, $constraint),
            'whereIn'      => $this->whereIn($query, $constraint),
            'whereNull'    => $this->whereNull($query, $constraint),
            'whereNotNull' => $this->whereNotNull($query, $constraint),
            'orderBy'      => $this->orderBy($query, $constraint),
            'limit'        => $this->limit($query, $constraint),
            'scope'        => $this->scope($query, $constraint),
            default        => null,
        };
    }

    /**
     * Apply a where constraint to the query.
     *
     * Routed through the base query builder, the equality-default operator and
     * raw value matching the descriptor recorded by the builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $constraint
     * @return void
     */
    private function where(Builder $query, array $constraint): void
    {
        $query->getQuery()->where($this->stringValue($constraint['column'] ?? ''), $this->stringValue($constraint['operator'] ?? '='), $constraint['value'] ?? null);
    }

    /**
     * Apply a where-in constraint to the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $constraint
     * @return void
     */
    private function whereIn(Builder $query, array $constraint): void
    {
        $query->getQuery()->whereIn($this->stringValue($constraint['column'] ?? ''), $this->arrayValue($constraint['values'] ?? []));
    }

    /**
     * Apply a where-null constraint to the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $constraint
     * @return void
     */
    private function whereNull(Builder $query, array $constraint): void
    {
        $query->getQuery()->whereNull($this->stringValue($constraint['column'] ?? ''));
    }

    /**
     * Apply a where-not-null constraint to the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $constraint
     * @return void
     */
    private function whereNotNull(Builder $query, array $constraint): void
    {
        $query->getQuery()->whereNotNull($this->stringValue($constraint['column'] ?? ''));
    }

    /**
     * Apply an order-by constraint to the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $constraint
     * @return void
     */
    private function orderBy(Builder $query, array $constraint): void
    {
        $query->getQuery()->orderBy($this->stringValue($constraint['column'] ?? ''), $this->stringValue($constraint['direction'] ?? 'asc'));
    }

    /**
     * Apply a hard limit to the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $constraint
     * @return void
     */
    private function limit(Builder $query, array $constraint): void
    {
        $query->getQuery()->limit($this->intValue($constraint['value'] ?? 0));
    }

    /**
     * Apply a named Eloquent scope to the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $constraint
     * @return void
     */
    private function scope(Builder $query, array $constraint): void
    {
        $name      = $this->stringValue($constraint['name'] ?? '');
        $arguments = $this->arrayValue($constraint['arguments'] ?? []);

        if ($name === '') {
            return;
        }

        $query->scopes($arguments === [] ? [$name] : [$name => $arguments]);
    }

    /**
     * Coerce a constraint operand to a string.
     *
     * @param  mixed  $value
     * @return string
     */
    private function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * Coerce a constraint operand to an integer.
     *
     * @param  mixed  $value
     * @return int
     */
    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Coerce a constraint operand to a list.
     *
     * @param  mixed  $value
     * @return array<array-key, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
