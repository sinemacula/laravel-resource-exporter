<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Exceptions\RowLimitExceeded;
use SineMacula\Exporter\ResourceExport;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\V3\ExporterTestCase;
use Tests\Support\V3\Models\User;
use Tests\Support\V3\Resources\UserResource;

/**
 * Integration tests for the query-aware paginate-or-stream endpoint helper.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ResourceExport::class)]
final class ResourceExportTest extends ExporterTestCase
{
    /**
     * It returns a single paginated page of JSON for a JSON request.
     *
     * @return void
     */
    public function testJsonRequestReturnsASinglePaginatedPage(): void
    {
        $this->seedUsers(25);

        $response = ResourceExport::forQuery(User::query(), UserResource::class)
            ->perPage(10)
            ->paginatedJsonOrStreamedExport($this->jsonRequest());

        self::assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true);

        self::assertIsArray($payload);
        self::assertCount(10, $payload['data']);
        self::assertSame(25, $payload['meta']['total']);
    }

    /**
     * It streams the full dataset - more rows than one page - for an export
     * request.
     *
     * @return void
     */
    public function testExportRequestStreamsMoreThanOnePage(): void
    {
        $this->seedUsers(25);

        $response = ResourceExport::forQuery(User::query(), UserResource::class)
            ->perPage(10)
            ->chunk(10)
            ->paginatedJsonOrStreamedExport($this->exportRequest());

        self::assertInstanceOf(StreamedResponse::class, $response);

        $lines = $this->dataLines($this->streamToString($response));

        self::assertGreaterThan(10, count($lines));
        self::assertCount(25, $lines);
    }

    /**
     * It enforces the row cap before any bytes are streamed.
     *
     * @return void
     */
    public function testRowCapIsEnforcedBeforeStreaming(): void
    {
        $this->seedUsers(5);

        $this->expectException(RowLimitExceeded::class);

        ResourceExport::forQuery(User::query(), UserResource::class)
            ->maxRows(2)
            ->paginatedJsonOrStreamedExport($this->exportRequest());
    }

    /**
     * It lifts the cap with unlimited().
     *
     * @return void
     */
    public function testUnlimitedLiftsTheCap(): void
    {
        $this->seedUsers(5);

        $response = ResourceExport::forQuery(User::query(), UserResource::class)
            ->maxRows(2)
            ->unlimited()
            ->paginatedJsonOrStreamedExport($this->exportRequest());

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertCount(5, $this->dataLines($this->streamToString($response)));
    }

    /**
     * It streams a large export at bounded peak memory.
     *
     * The keyset source hydrates one chunk at a time, so peak memory must not
     * scale with the row count. The ceiling is deliberately generous; if this
     * proves environment-flaky it may be marked skipped without affecting the
     * functional guarantees proven above.
     *
     * @return void
     */
    public function testLargeExportStreamsAtBoundedPeakMemory(): void
    {
        $this->seedUsers(4000);

        $response = ResourceExport::forQuery(User::query(), UserResource::class)
            ->chunk(500)
            ->unlimited()
            ->paginatedJsonOrStreamedExport($this->exportRequest());

        self::assertInstanceOf(StreamedResponse::class, $response);

        $before = memory_get_usage(true);
        memory_reset_peak_usage();

        $lines = $this->dataLines($this->streamToString($response));

        $delta = memory_get_peak_usage(true) - $before;

        self::assertCount(4000, $lines);
        self::assertLessThan(48 * 1024 * 1024, $delta, 'Streaming peak memory grew unexpectedly.');
    }

    /**
     * Build a JSON-preferring request.
     *
     * @return \Illuminate\Http\Request
     */
    private function jsonRequest(): Request
    {
        return Request::create('/users', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
    }

    /**
     * Build a CSV-preferring export request.
     *
     * @return \Illuminate\Http\Request
     */
    private function exportRequest(): Request
    {
        return Request::create('/users', 'GET', server: ['HTTP_ACCEPT' => 'text/csv']);
    }

    /**
     * Split a CSV body into its data lines, dropping the heading and trailer.
     *
     * @param  string  $csv
     * @return list<string>
     */
    private function dataLines(string $csv): array
    {
        $lines = array_values(array_filter(explode("\n", $csv), static fn (string $line): bool => $line !== ''));

        array_shift($lines);

        return $lines;
    }
}
