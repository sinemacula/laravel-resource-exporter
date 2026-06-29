<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Http\Middleware;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\LazyCollection;
use SineMacula\Exporter\Http\ExportNegotiator;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\TabularSchema;
use SineMacula\Exporter\Sources\LazyCollectionSource;
use Symfony\Component\HttpFoundation\Response;

/**
 * Legacy content-negotiation middleware.
 *
 * The zero-touch fallback for teams that cannot - or do not want to - add the
 * RespondsWithExports trait to their resources. Applied to a route, it inspects
 * the already-rendered JSON response and, when an export format is negotiated,
 * re-decodes that JSON and blind-flattens it into the requested tabular format.
 * It is deliberately limited and shipped off by default: it cannot use a
 * TabularSchema, cannot stream the source set (the JSON is already buffered),
 * and only strips the paginator data envelope - the trait is the recommended
 * path, documented as such. When the body cannot be flattened the original JSON
 * response is returned untouched, with Vary: Accept set so caches never serve
 * the wrong representation.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class NegotiateExports
{
    /**
     * Create a new legacy negotiation middleware.
     *
     * @param  \SineMacula\Exporter\Http\ExportNegotiator  $negotiator
     */
    public function __construct(

        /** The shared negotiator resolving formats and streaming writers. */
        private ExportNegotiator $negotiator = new ExportNegotiator,
    ) {}

    /**
     * Handle an incoming request, converting the response when an export format
     * is negotiated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, \Closure $next): Response
    {
        $response = $next($request);

        $format = $this->negotiator->resolve($request);

        if (!$this->negotiator->isTabular($format)) {
            return ExportNegotiator::varyAccept($response);
        }

        $rows = $this->flatten($response);

        if ($rows === null) {
            return ExportNegotiator::varyAccept($response);
        }

        return $this->negotiator->streamExport(
            new LazyCollectionSource(LazyCollection::make($rows)),
            $this->schema($request, $rows),
            $format,
            $request,
        );
    }

    /**
     * Extract the flat rows from a JSON response, or null when it cannot.
     *
     * Only a successful JSON response is considered; the paginator data
     * envelope is unwrapped, a bare list is taken as-is, and a single object is
     * treated as a one-row export.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @return list<array<array-key, mixed>>|null
     */
    private function flatten(Response $response): ?array
    {
        if (!$response instanceof JsonResponse || !$response->isSuccessful()) {
            return null;
        }

        $data = $response->getData(true);

        if (!is_array($data)) {
            return null;
        }

        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        return $this->rows($data);
    }

    /**
     * Normalise a decoded payload into a list of associative rows.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<array<array-key, mixed>>|null
     */
    private function rows(array $data): ?array
    {
        if ($data === []) {
            return null;
        }

        if (array_is_list($data)) {

            $rows = array_values(array_filter($data, 'is_array'));

            return count($rows) === count($data) ? $rows : null;
        }

        return [$data];
    }

    /**
     * Build an ad-hoc schema whose columns mirror the first row's keys.
     *
     * Each column reads its value straight from the decoded row and renders a
     * scalar as-is, JSON-encoding any nested value - the blind flatten the
     * legacy fallback is documented to perform.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  list<array<array-key, mixed>>  $rows
     * @return \SineMacula\Exporter\Schema\TabularSchema
     */
    private function schema(Request $request, array $rows): TabularSchema
    {
        $columns = [];

        foreach (array_keys($rows[0]) as $key) {

            $column = (string) $key;

            $columns[] = Column::make($column)->resolveUsing(
                static fn (array|object $item): bool|float|int|string|null => self::scalar(data_get($item, $column)),
            );
        }

        return new class ($request, $columns) extends TabularSchema {
            /** @var list<\SineMacula\Exporter\Schema\Column> The ad-hoc columns mirroring the decoded payload */
            private readonly array $columns;

            /**
             * Create the ad-hoc legacy schema.
             *
             * @param  \Illuminate\Http\Request  $request
             * @param  list<\SineMacula\Exporter\Schema\Column>  $columns
             */
            public function __construct(

                // The request the export is built for.
                Request $request,

                array $columns,
            ) {
                parent::__construct($request);

                $this->columns = $columns;
            }

            /**
             * Get the ad-hoc columns mirroring the decoded payload.
             *
             * @return list<\SineMacula\Exporter\Schema\Column>
             */
            #[\Override]
            public function columns(): array
            {
                return $this->columns;
            }
        };
    }

    /**
     * Reduce a decoded value to a scalar, JSON-encoding nested structures.
     *
     * @param  mixed  $value
     * @return bool|float|int|string|null
     */
    private static function scalar(mixed $value): bool|float|int|string|null
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '' : $encoded;
    }
}
