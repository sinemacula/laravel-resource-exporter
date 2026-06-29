<?php

declare(strict_types = 1);

namespace Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Contracts\ExporterException;
use SineMacula\Exporter\Exceptions\InvalidExportSchema;
use SineMacula\Exporter\Exceptions\MissingXlsxDependency;
use SineMacula\Exporter\Exceptions\NoTabularRepresentation;
use SineMacula\Exporter\Exceptions\RowLimitExceeded;
use SineMacula\Exporter\Exceptions\SinkException;
use SineMacula\Exporter\Exceptions\XlsxRowLimitExceeded;
use SineMacula\Exporter\Exceptions\XmlExportException;

/**
 * Marker-interface coverage for the exporter exceptions.
 *
 * The package's exceptions extend a mix of SPL and HTTP base classes, so the
 * shared marker is what lets a consumer catch any exporter failure with one
 * clause. These tests pin that contract.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversNothing]
final class ExporterExceptionTest extends TestCase
{
    /**
     * The exporter exception instances under test.
     *
     * @return iterable<string, array{0: \Throwable}>
     */
    public static function exceptions(): iterable
    {
        yield 'invalid-schema' => [InvalidExportSchema::column('id', 'the column key is empty')];
        yield 'missing-xlsx' => [MissingXlsxDependency::create()];
        yield 'no-tabular' => [NoTabularRepresentation::forResource('Foo')];
        yield 'row-limit' => [RowLimitExceeded::forCount(10, 5)];
        yield 'sink' => [new SinkException('boom')];
        yield 'xlsx-row-limit' => [XlsxRowLimitExceeded::forLimit(1048576)];
        yield 'xml-export' => [new XmlExportException('bad xml')];
    }

    /**
     * It marks every exporter exception with the shared interface.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    #[DataProvider('exceptions')]
    public function testEveryExporterExceptionImplementsTheMarker(\Throwable $exception): void
    {
        self::assertInstanceOf(ExporterException::class, $exception);
    }
}
