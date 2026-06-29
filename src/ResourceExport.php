<?php

declare(strict_types = 1);

namespace SineMacula\Exporter;

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use SineMacula\Exporter\Contracts\HierarchicalWriter;
use SineMacula\Exporter\Contracts\ProvidesTabularExport;
use SineMacula\Exporter\Events\ExportCompleted;
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
 * configurable row cap (lifted with unlimited()), an explicit full-set
 * authorization decision, and an audit hook fired around the streamed export.
 * The authorization is not an automatic re-check - the caller must either
 * register one with authorizeUsing() or consciously opt out with
 * withoutAuthorization(), and streaming without either throws a LogicException.
 * The builder is request-explicit and resolves the current request only as a
 * convenience default (Octane-safe).
 *
 * Two behaviours are by design. A streamed CSV/TSV export is genuinely
 * constant-memory, but XLSX finalises on close and therefore buffers the whole
 * workbook before the response flushes - so a large XLSX served synchronously
 * over HTTP holds the set in memory; queue it (Exporter::queue()) when the set
 * can be large. And an empty result set streams a header-less file (no rows
 * means no header is written), so an empty export is a zero-byte body rather
 * than a column header alone.
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

    /** @var (\Closure(\Illuminate\Http\Request, \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): mixed)|null The full-set authorization re-check */
    private ?\Closure $authorization = null;

    /** @var (\Closure(array<string, mixed>): void)|null The audit hook fired around a streamed export */
    private ?\Closure $audit = null;

    /** @var bool Whether the explicit full-set authorization requirement is waived */
    private bool $withoutAuthorization = false;

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
     * @param  \Closure(\Illuminate\Http\Request, \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): mixed  $callback
     * @return $this
     */
    public function authorizeUsing(\Closure $callback): static
    {
        $this->authorization = $callback;

        return $this;
    }

    /**
     * Waive the explicit full-set authorization requirement for this export.
     *
     * A conscious opt-out, not a default: use it only when access to the full
     * set is already enforced upstream (route middleware, a policy applied to
     * the query). Streaming without either this or authorizeUsing() throws.
     *
     * @return $this
     */
    public function withoutAuthorization(): static
    {
        $this->withoutAuthorization = true;

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
     * @throws \LogicException
     * @throws \SineMacula\Exporter\Exceptions\NoTabularRepresentation
     * @throws \SineMacula\Exporter\Exceptions\RowLimitExceeded
     */
    public function paginatedJsonOrStreamedExport(?Request $request = null): Response
    {
        $container = Container::getInstance();
        $request ??= $container->make('request');
        $negotiator = $container->make(ExportNegotiator::class);

        $format = $negotiator->resolve($request);

        if ($negotiator->isTabular($format)) {
            return $this->streamResponse($negotiator, $format, $request);
        }

        $writer = $negotiator->hierarchicalWriterFor($format, $request);

        return $writer === null
            ? $this->jsonResponse($request)
            : $this->streamHierarchicalResponse($negotiator, $writer, $format, $request);
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
     * @throws \LogicException
     * @throws \SineMacula\Exporter\Exceptions\NoTabularRepresentation
     * @throws \SineMacula\Exporter\Exceptions\RowLimitExceeded
     */
    private function streamResponse(ExportNegotiator $negotiator, string $format, Request $request): Response
    {
        $auditor = new ExportAuditor;

        $this->guardAuthorizationDecision();
        $this->authorizeFullSet($auditor, $request);
        $this->enforceRowCap();

        $schema = $this->schema($request);
        $source = new QueryChunkSource($this->query, $this->chunkSize);
        $source->guardKeysetOrdering();

        return $negotiator->streamExport(
            $source,
            $schema,
            $format,
            $request,
            function (int $rows) use ($auditor, $request, $schema, $format): void {
                $this->complete($auditor, $request, $schema->filename(), $format, $rows);
            },
        );
    }

    /**
     * Build the streamed full-dataset hierarchical response.
     *
     * @param  \SineMacula\Exporter\Http\ExportNegotiator  $negotiator
     * @param  \SineMacula\Exporter\Contracts\HierarchicalWriter  $writer
     * @param  string  $format
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \LogicException
     * @throws \SineMacula\Exporter\Exceptions\RowLimitExceeded
     */
    private function streamHierarchicalResponse(ExportNegotiator $negotiator, HierarchicalWriter $writer, string $format, Request $request): Response
    {
        $auditor  = new ExportAuditor;
        $resource = $this->resourceClass;

        $this->guardAuthorizationDecision();
        $this->authorizeFullSet($auditor, $request);
        $this->enforceRowCap();

        $source = new QueryChunkSource($this->query, $this->chunkSize);
        $source->guardKeysetOrdering();

        return $negotiator->streamHierarchical(
            $source,
            static fn (mixed $item): array => (new $resource($item))->resolve($request),
            $writer,
            $format,
            $request,
            function (int $rows) use ($auditor, $request, $format): void {
                $this->complete($auditor, $request, null, $format, $rows);
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
     * Require the caller to have made an explicit full-set authorization
     * decision before any bytes stream.
     *
     * The full set is a different, larger response than the visible page, so
     * the decision is never implicit: the caller must either register a
     * re-check with authorizeUsing() or consciously opt out with
     * withoutAuthorization(). Silence is not consent, so neither throws here.
     *
     * @return void
     *
     * @throws \LogicException
     */
    private function guardAuthorizationDecision(): void
    {
        if ($this->authorization !== null || $this->withoutAuthorization) {
            return;
        }

        throw new \LogicException('A streamed full-set export must register an authorization check with authorizeUsing(), or explicitly opt out with withoutAuthorization().');
    }

    /**
     * Run the registered full-set authorization check, if any.
     *
     * Routed through the shared auditor so the streamed path and the queued
     * pipeline enforce full-set authorization at the same point. When the
     * caller opted out with withoutAuthorization() no check is registered and
     * this is a deliberate no-op - the guard upstream has already required that
     * explicit decision.
     *
     * @param  \SineMacula\Exporter\Export\ExportAuditor  $auditor
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    private function authorizeFullSet(ExportAuditor $auditor, Request $request): void
    {
        $authorization = $this->authorization;

        $auditor->authorize(
            callback: $authorization === null ? null : fn (): mixed => $authorization($request, $this->query),
        );
    }

    /**
     * Enforce the configured row cap before any bytes are streamed.
     *
     * This runs a COUNT over the (filtered) query before the export starts, so
     * an oversized set is rejected up front rather than after streaming bytes.
     * The COUNT is an extra round-trip whose cost grows with the result set and
     * the query's complexity; lift the cap with unlimited() (or raise it with
     * maxRows()) when the count is known to be safe and the round-trip is not
     * wanted.
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
     * @param  string|null  $filename
     * @param  string  $format
     * @param  int  $rows
     * @return void
     */
    private function complete(ExportAuditor $auditor, Request $request, ?string $filename, string $format, int $rows): void
    {
        $actorId = $this->actorId($request);

        $auditor->completed(new ExportCompleted(
            $actorId,
            $rows,
            $filename,
            $format,
            CarbonImmutable::now(),
        ));

        if ($this->audit === null) {
            return;
        }

        ($this->audit)([
            'actor_id'  => $actorId,
            'row_count' => $rows,
            'filename'  => $filename,
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
