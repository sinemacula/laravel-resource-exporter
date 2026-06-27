<?php

declare(strict_types = 1);

namespace Tests\Support\V3\Writers;

use SineMacula\Exporter\Contracts\Sink;
use SineMacula\Exporter\Contracts\Writer;
use SineMacula\Exporter\Schema\TabularSchema;
use SineMacula\Exporter\Writers\CsvWriter;

/**
 * Custom tabular writer for the config-format negotiation tests.
 *
 * Emits CSV bytes through the built-in writer but reports its own media type,
 * so a format registered through config('exporter.formats') can be proven
 * negotiable end-to-end with a Content-Type distinct from the built-ins.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final readonly class ReportWriter implements Writer
{
    /** @var \SineMacula\Exporter\Writers\CsvWriter The delegate producing the bytes */
    private CsvWriter $writer;

    /**
     * Create a new report writer.
     */
    public function __construct()
    {
        $this->writer = new CsvWriter;
    }

    /**
     * Get the media type this writer emits.
     *
     * @return string
     */
    #[\Override]
    public function mediaType(): string
    {
        return 'application/x-report';
    }

    /**
     * Write the shaped rows into the given sink.
     *
     * @param  iterable<int, array<string, \SineMacula\Exporter\Schema\CellValue>>  $rows
     * @param  \SineMacula\Exporter\Schema\TabularSchema  $schema
     * @param  \SineMacula\Exporter\Contracts\Sink  $sink
     * @return void
     */
    #[\Override]
    public function write(iterable $rows, TabularSchema $schema, Sink $sink): void
    {
        $this->writer->write($rows, $schema, $sink);
    }
}
