<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Testing;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Assert as PHPUnit;
use SineMacula\Exporter\Export\ExportSpecification;
use SineMacula\Exporter\Jobs\ExportToDiskJob;

/**
 * Recording export test double.
 *
 * The test-time stand-in returned by Exporter::fake() and bound in the
 * container under its own class name. While it is bound the fluent export
 * builder and the queued-export builder consult it before doing any real work:
 * each terminal verb records what would have been exported (the format, the
 * filename, the disk and path, the source row count, or the queued
 * specification) instead of producing bytes, dispatching a job, or touching a
 * disk. Queued exports are additionally intercepted through the bus fake so the
 * job is captured rather than run.
 *
 * Applications then assert against the ledger - assertDownloaded(),
 * assertStored(), assertQueued(), assertExportedRows(), assertNothingExported()
 * - mirroring the ergonomics of Storage::fake() and Bus::fake(). Each assertion
 * returns the fake so they can be chained.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ExporterFake
{
    /** @var list<\SineMacula\Exporter\Testing\RecordedExport> The recorded export ledger */
    private array $exports = [];

    /**
     * Create a new recording export double, capturing queued export jobs.
     */
    public function __construct()
    {
        Bus::fake([ExportToDiskJob::class]);
    }

    /**
     * Get the active fake bound in the container, or null when none is.
     *
     * @return self|null
     */
    public static function active(): ?self
    {
        $container = Container::getInstance();

        if (!$container->bound(self::class)) {
            return null;
        }

        return $container->make(self::class);
    }

    /**
     * Record a download (or response) export.
     *
     * @param  string  $filename
     * @param  string  $resolvedFilename
     * @param  string  $format
     * @param  int  $rows
     * @return void
     */
    public function recordDownload(string $filename, string $resolvedFilename, string $format, int $rows): void
    {
        $this->exports[] = new RecordedExport('download', $format, $filename, $resolvedFilename, null, null, $rows);
    }

    /**
     * Record a stored-to-disk export.
     *
     * @param  string  $disk
     * @param  string  $path
     * @param  string  $format
     * @param  int  $rows
     * @return void
     */
    public function recordStored(string $disk, string $path, string $format, int $rows): void
    {
        $this->exports[] = new RecordedExport('store', $format, null, null, $disk, $path, $rows);
    }

    /**
     * Record a buffered-to-string export.
     *
     * @param  string  $format
     * @param  int  $rows
     * @return void
     */
    public function recordString(string $format, int $rows): void
    {
        $this->exports[] = new RecordedExport('string', $format, null, null, null, null, $rows);
    }

    /**
     * Record a streamed-to-resource export.
     *
     * @param  string  $format
     * @param  int  $rows
     * @return void
     */
    public function recordStream(string $format, int $rows): void
    {
        $this->exports[] = new RecordedExport('stream', $format, null, null, null, null, $rows);
    }

    /**
     * Record a queued export from its serializable specification.
     *
     * @param  \SineMacula\Exporter\Export\ExportSpecification  $specification
     * @return void
     */
    public function recordQueue(ExportSpecification $specification): void
    {
        $this->exports[] = new RecordedExport('queue', $specification->format, $specification->filename, null, $specification->disk, $specification->path, null, $specification);
    }

    /**
     * Get every recorded export, in order.
     *
     * @return list<\SineMacula\Exporter\Testing\RecordedExport>
     */
    public function recorded(): array
    {
        return $this->exports;
    }

    /**
     * Assert an export was downloaded, optionally with the given filename.
     *
     * The filename matches either the bare hint passed to download()/as() or
     * the resolved, extension-bearing download name.
     *
     * @param  string|null  $filename
     * @return $this
     */
    public function assertDownloaded(?string $filename = null): self
    {
        $matches = array_filter(
            $this->exports,
            static fn (RecordedExport $export): bool => $export->type === 'download'
                && ($filename === null || $export->filename === $filename || $export->resolvedFilename === $filename),
        );

        PHPUnit::assertNotEmpty(
            $matches,
            $filename === null
                ? 'Expected an export to be downloaded, but none were.'
                : "Expected an export to be downloaded as [{$filename}], but none were.",
        );

        return $this;
    }

    /**
     * Assert an export was stored, optionally on the given disk and/or path.
     *
     * @param  string|null  $disk
     * @param  string|null  $path
     * @return $this
     */
    public function assertStored(?string $disk = null, ?string $path = null): self
    {
        $matches = array_filter(
            $this->exports,
            static fn (RecordedExport $export): bool => $export->type === 'store'
                && ($disk === null || $export->disk === $disk)
                && ($path === null || $export->path === $path),
        );

        PHPUnit::assertNotEmpty($matches, 'Expected an export to be stored, but none matched.');

        return $this;
    }

    /**
     * Assert an export was queued, optionally matching the given specification.
     *
     * @param  (\Closure(\SineMacula\Exporter\Export\ExportSpecification): bool)|null  $callback
     * @return $this
     */
    public function assertQueued(?\Closure $callback = null): self
    {
        $queued = array_filter($this->exports, static fn (RecordedExport $export): bool => $export->type === 'queue');

        if ($callback === null) {
            PHPUnit::assertNotEmpty($queued, 'Expected an export to be queued, but none were.');

            return $this;
        }

        $matches = array_filter(
            $queued,
            static fn (RecordedExport $export): bool => $export->specification !== null && $callback($export->specification) === true,
        );

        PHPUnit::assertNotEmpty($matches, 'Expected a queued export matching the given specification, but none did.');

        return $this;
    }

    /**
     * Assert the total number of exported source rows, by value or predicate.
     *
     * @param  \Closure(int): bool|int  $count
     * @return $this
     */
    public function assertExportedRows(\Closure|int $count): self
    {
        $total = array_sum(array_map(static fn (RecordedExport $export): int => $export->rows ?? 0, $this->exports));

        if ($count instanceof \Closure) {
            PHPUnit::assertTrue($count($total) === true, "Unexpected exported row total [{$total}].");

            return $this;
        }

        PHPUnit::assertSame($count, $total, "Expected [{$count}] exported rows, but recorded [{$total}].");

        return $this;
    }

    /**
     * Assert that nothing was exported.
     *
     * @return $this
     */
    public function assertNothingExported(): self
    {
        PHPUnit::assertEmpty($this->exports, 'Expected no exports, but some were recorded.');

        return $this;
    }
}
