<?php

declare(strict_types = 1);

namespace SineMacula\Exporter;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use SineMacula\Exporter\Contracts\ProvidesTabularExport;
use SineMacula\Exporter\Exceptions\NoTabularRepresentation;
use SineMacula\Exporter\Exceptions\RowLimitExceeded;
use SineMacula\Exporter\Export\ExportAuditor;
use SineMacula\Exporter\Http\ExportNegotiator;
use SineMacula\Exporter\Schema\TabularSchema;
use SineMacula\Exporter\Sources\QueryChunkSource;
use Symfony\Component\HttpFoundation\Response;

/**
 * Query-aware export endpoint helper.
 *
 * Serves a full dataset and its paginated JSON from the same endpoint,
 * switching cardinality by content negotiation: a JSON request returns a normal
 * paginated resource collection, while an export request streams the whole
 * query through the schema and writer via keyset chunks (QueryChunkSource) at
 * constant memory.
 *
 * Because the export path changes cardinality from a page to the full set, it
 * carries the safety rails that page-scoped responses do not need: a
 * configurable row cap (lifted with unlimited()), a full-set authorization
 * re-check, and an audit hook fired around the streamed export. The builder is
 * request-explicit and resolves the current request only as a convenience
 * default (Octane-safe).
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ResourceExport
{
    /** @var bool Whether the row cap is lifted */
    private bool $unlimited = false;

    /** @var int|null The maximum number of rows an export may stream */
    private ?int $maxRows;

    /** @var int The page size used for the paginated JSON response */
    private int $perPage;

    /** @var int The keyset chunk size used while streaming the full set */
    private int $chunkSize;

    /** @var (\Closure(\Illuminate\Http\Request, \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): void)|null The full-set authorization re-check */
    private ?\Closure $authorization = null;

    /** @var (\Closure(array<string, mixed>): void)|null The audit hook fired around a streamed export */
    private ?\Closure $audit = null;

    /**
     * Create a new query-aware export helper.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resourceClass
     */
    private function __construct(

        /** @var \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> The query the export runs over */
        private readonly Builder $query,

        /** @var class-string<\Illuminate\Http\Resources\Json\JsonResource> The resource class describing the export */
        private readonly string $resourceClass,
    ) {
        $this->maxRows   = self::configInt('exporter.negotiation.max_rows', 10000);
        $this->perPage   = self::configInt('exporter.negotiation.per_page', 15)     ?? 15;
        $this->chunkSize = self::configInt('exporter.negotiation.chunk_size', 1000) ?? 1000;
    }

    /**
     * Begin a query-aware export for the given query and resource class.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resourceClass
     * @return self
     */
    public static function forQuery(Builder $query, string $resourceClass): self
    {
        return new self($query, $resourceClass);
    }

    /**
     * Lift the row cap so the full dataset streams regardless of size.
     *
     * @return $this
     */
    public function unlimited(): static
    {
        $this->unlimited = true;

        return $this;
    }

    /**
     * Set the maximum number of rows an export may stream.
     *
     * @param  int  $max
     * @return $this
     */
    public function maxRows(int $max): static
    {
        $this->maxRows   = $max;
        $this->unlimited = false;

        return $this;
    }

    /**
     * Set the page size used for the paginated JSON response.
     *
     * @param  int  $perPage
     * @return $this
     */
    public function perPage(int $perPage): static
    {
        $this->perPage = $perPage;

        return $this;
    }

    /**
     * Set the keyset chunk size used while streaming the full set.
     *
     * @param  int  $size
     * @return $this
     */
    public function chunk(int $size): static
    {
        $this->chunkSize = $size;

        return $this;
    }

    /**
     * Register the full-set authorization re-check for the export path.
     *
     * @param  \Closure(\Illuminate\Http\Request, \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): void  $callback
     * @return $this
     */
    public function authorizeUsing(\Closure $callback): static
    {
        $this->authorization = $callback;

        return $this;
    }

    /**
     * Register the audit hook fired around a streamed export.
     *
     * @param  \Closure(array<string, mixed>): void  $callback
     * @return $this
     */
    public function auditUsing(\Closure $callback): static
    {
        $this->audit = $callback;

        return $this;
    }

    /**
     * Return paginated JSON for a JSON request, or stream the full dataset for
     * an export request.
     *
     * @param  \Illuminate\Http\Request|null  $request
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \SineMacula\Exporter\Exceptions\NoTabularRepresentation
     * @throws \SineMacula\Exporter\Exceptions\RowLimitExceeded
     */
    public function paginatedJsonOrStreamedExport(?Request $request = null): Response
    {
        $container = Container::getInstance();
        $request ??= $container->make('request');
        $negotiator = $container->make(ExportNegotiator::class);

        $format = $negotiator->resolve($request);

        return $negotiator->isTabular($format)
            ? $this->streamResponse($negotiator, $format, $request)
            : $this->jsonResponse($request);
    }

    /**
     * Read an integer configuration value with a fallback.
     *
     * @param  string  $key
     * @param  int|null  $default
     * @return int|null
     */
    private static function configInt(string $key, ?int $default): ?int
    {
        $value = Config::get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Build the paginated JSON collection response for a JSON request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    private function jsonResponse(Request $request): Response
    {
        $resource = $this->resourceClass;
        $page     = $this->query->paginate($this->perPage);

        return ExportNegotiator::varyAccept(
            $resource::collection($page)->toResponse($request),
        );
    }

    /**
     * Build the streamed full-dataset export response for an export request.
     *
     * @param  \SineMacula\Exporter\Http\ExportNegotiator  $negotiator
     * @param  string  $format
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \SineMacula\Exporter\Exceptions\NoTabularRepresentation
     * @throws \SineMacula\Exporter\Exceptions\RowLimitExceeded
     */
    private function streamResponse(ExportNegotiator $negotiator, string $format, Request $request): Response
    {
        $auditor = new ExportAuditor;

        $this->authorizeFullSet($auditor, $request);
        $this->enforceRowCap();

        $schema = $this->schema($request);

        return $negotiator->streamExport(
            new QueryChunkSource($this->query, $this->chunkSize),
            $schema,
            $format,
            $request,
            function (int $rows) use ($auditor, $request, $schema, $format): void {
                $this->complete($auditor, $request, $schema, $format, $rows);
            },
        );
    }

    /**
     * Resolve the tabular schema for the resource, or throw a 406.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \SineMacula\Exporter\Schema\TabularSchema
     *
     * @throws \SineMacula\Exporter\Exceptions\NoTabularRepresentation
     */
    private function schema(Request $request): TabularSchema
    {
        if (!is_a($this->resourceClass, ProvidesTabularExport::class, true)) {
            throw NoTabularRepresentation::forResource($this->resourceClass);
        }

        /** @var \Illuminate\Http\Resources\Json\JsonResource&\SineMacula\Exporter\Contracts\ProvidesTabularExport $probe */
        $probe = new $this->resourceClass(null);

        return $probe->tabular($request);
    }

    /**
     * Re-check authorization for the full dataset, not just the visible page.
     *
     * Routed through the shared auditor so the streamed path and the queued
     * pipeline enforce full-set authorization at the same point.
     *
     * @param  \SineMacula\Exporter\Export\ExportAuditor  $auditor
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    private function authorizeFullSet(ExportAuditor $auditor, Request $request): void
    {
        $authorization = $this->authorization;

        $auditor->authorize(
            callback: $authorization === null ? null : function () use ($authorization, $request): void {
                $authorization($request, $this->query);
            },
        );
    }

    /**
     * Enforce the configured row cap before any bytes are streamed.
     *
     * @return void
     *
     * @throws \SineMacula\Exporter\Exceptions\RowLimitExceeded
     */
    private function enforceRowCap(): void
    {
        if ($this->unlimited || $this->maxRows === null) {
            return;
        }

        $count = (clone $this->query)->toBase()->getCountForPagination();

        if ($count > $this->maxRows) {
            throw RowLimitExceeded::forCount($count, $this->maxRows);
        }
    }

    /**
     * Fire the shared audit event once the streamed export has completed.
     *
     * Called with the real row count when the stream finishes, this dispatches
     * the same pinned ExportCompleted event the queued pipeline fires - through
     * the same auditor - then forwards the payload to the optional user audit
     * hook for backwards compatibility.
     *
     * @param  \SineMacula\Exporter\Export\ExportAuditor  $auditor
     * @param  \Illuminate\Http\Request  $request
     * @param  \SineMacula\Exporter\Schema\TabularSchema  $schema
     * @param  string  $format
     * @param  int  $rows
     * @return void
     */
    private function complete(ExportAuditor $auditor, Request $request, TabularSchema $schema, string $format, int $rows): void
    {
        $actorId = $this->actorId($request);

        $auditor->completed($actorId, $rows, $schema->filename(), $format);

        if ($this->audit === null) {
            return;
        }

        ($this->audit)([
            'actor_id'  => $actorId,
            'row_count' => $rows,
            'filename'  => $schema->filename(),
            'format'    => $format,
        ]);
    }

    /**
     * Resolve the initiating actor's identifier from the request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return int|string|null
     */
    private function actorId(Request $request): int|string|null
    {
        $user = $request->user();
        $id   = $user instanceof Authenticatable ? $user->getAuthIdentifier() : null;

        return is_int($id) || is_string($id) ? $id : null;
    }
}
