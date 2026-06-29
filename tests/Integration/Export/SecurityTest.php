<?php

declare(strict_types = 1);

namespace Tests\Integration\Export;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Engine;
use SineMacula\Exporter\Export\ExportAuditor;
use SineMacula\Exporter\ResourceExport;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\TabularSchema;
use SineMacula\Exporter\Sinks\StringSink;
use SineMacula\Exporter\Sources\QueryChunkSource;
use SineMacula\Exporter\Writers\CsvWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\Concerns\ShapesRows;
use Tests\Support\ExporterTestCase;
use Tests\Support\Models\User;
use Tests\Support\Resources\UserResource;
use Tests\Support\Schema\ArraySchema;
use Tests\Support\Schema\FlexibleSchema;

/**
 * Consolidated security guarantees for the export pipeline.
 *
 * Pins the three security properties the release gate requires: CSV formula
 * injection is neutralised by default for every dangerous leading character (=
 * + - @ tab CR); a field the request gates out cannot leak through any export
 * path, neither the raw accessor nor the aggregate derivation that rewrites the
 * same query; and the streamed full-dataset query export re-checks
 * authorization for the whole set before a byte is sent, not just the page.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CsvWriter::class)]
#[CoversClass(Engine::class)]
#[CoversClass(Column::class)]
#[CoversClass(QueryChunkSource::class)]
#[CoversClass(ResourceExport::class)]
#[CoversClass(ExportAuditor::class)]
final class SecurityTest extends ExporterTestCase
{
    use ShapesRows;

    /**
     * CSV formula injection is neutralised by default for every trigger.
     *
     * @return void
     */
    public function testFormulaInjectionNeutralisedByDefaultForEveryTrigger(): void
    {
        $request  = Request::create('/');
        $columns  = [Column::make('payload', 'Payload')];
        $triggers = ['=2+5', '+2', '-3', '@SUM', "\tTAB", "\rCR"];

        $rows = $this->shapeRows(array_map(static fn (string $value): array => ['payload' => $value], $triggers), $columns, $request);
        $sink = new StringSink;

        (new CsvWriter)->write($rows, new ArraySchema($request, $columns), $sink);

        $output = $sink->contents();

        foreach (['=2+5', '+2', '-3', '@SUM'] as $payload) {
            self::assertStringContainsString('\'' . $payload, $output, 'The [' . $payload . '] formula trigger must be escaped.');
        }

        self::assertStringContainsString("'\t", $output, 'A leading tab must be escaped.');
        self::assertStringContainsString("'\r", $output, 'A leading carriage return must be escaped.');
    }

    /**
     * A request-gated field never leaks through the accessor or the aggregate.
     *
     * @return void
     */
    public function testGatedFieldCannotLeakViaTheAccessorOrAggregatePath(): void
    {
        $this->seedUsers(1);
        $this->seedOrders(1, [10, 20]);

        $unprivileged = $this->exportGatedBy(false);
        $privileged   = $this->exportGatedBy(true);

        self::assertStringNotContainsString('secret-1', $unprivileged, 'The gated accessor must not leak the secret.');
        self::assertStringNotContainsString('Secret', $unprivileged, 'The gated column must be absent entirely.');
        self::assertStringContainsString('2', $unprivileged, 'The aggregate over the rewritten query must still render.');
        self::assertStringContainsString('secret-1', $privileged, 'The privileged request must surface the gated value.');
    }

    /**
     * The streamed full-set query export is denied before any byte is sent.
     *
     * @return void
     */
    public function testFullSetAuthorizationIsReCheckedBeforeStreaming(): void
    {
        $this->seedUsers(5);

        $this->expectException(AuthorizationException::class);

        ResourceExport::forQuery(User::query(), UserResource::class)
            ->authorizeUsing(static function (Request $request, Builder $query): void {
                throw new AuthorizationException('Full-set export denied.');
            })
            ->paginatedJsonOrStreamedExport($this->csvRequest());
    }

    /**
     * An authorized full-set query export streams the entire dataset.
     *
     * @return void
     */
    public function testAuthorizedFullSetStreamsTheEntireDataset(): void
    {
        $this->seedUsers(5);

        $checked  = false;
        $response = ResourceExport::forQuery(User::query(), UserResource::class)
            ->authorizeUsing(static function (Request $request, Builder $query) use (&$checked): void {
                $checked = true;
            })
            ->paginatedJsonOrStreamedExport($this->csvRequest());

        self::assertInstanceOf(StreamedResponse::class, $response);

        $body  = $this->streamToString($response);
        $lines = array_values(array_filter(explode("\n", $body), static fn (string $line): bool => $line !== ''));

        self::assertTrue($checked, 'The full-set authorization callback must run.');
        self::assertCount(6, $lines, 'The heading row plus all five data rows must stream.');
        self::assertSame('ID,Name,Email,Active,Joined', $lines[0]);
        self::assertStringContainsString('1,"User 1",user1@example.test', $body);
        self::assertStringContainsString('5,"User 5",user5@example.test', $body);
    }

    /**
     * Export the users query as CSV with the secret gated by the decision.
     *
     * @param  bool  $privileged
     * @return string
     */
    private function exportGatedBy(bool $privileged): string
    {
        $columns = [
            Column::make('id', 'ID'),
            Column::make('secret', 'Secret')->visible(static fn (Request $request): bool => $privileged),
            Column::make('orders', 'Orders')->count(),
        ];

        $sink = new StringSink;

        (new Engine)->export(new QueryChunkSource(User::query()), $this->schema($columns), Request::create('/'), new CsvWriter, $sink);

        return $sink->contents();
    }

    /**
     * Build a flexible schema over the given columns.
     *
     * @param  list<\SineMacula\Exporter\Schema\Column>  $columns
     * @return \SineMacula\Exporter\Schema\TabularSchema
     */
    private function schema(array $columns): TabularSchema
    {
        return new FlexibleSchema(Request::create('/'), $columns);
    }

    /**
     * Build a CSV-preferring export request.
     *
     * @return \Illuminate\Http\Request
     */
    private function csvRequest(): Request
    {
        return Request::create('/users', 'GET', server: ['HTTP_ACCEPT' => 'text/csv']);
    }
}
