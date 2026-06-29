<?php

declare(strict_types = 1);

namespace Tests\Support\Concerns;

use OpenSpout\Reader\XLSX\Reader;

/**
 * Reads a generated XLSX workbook back into typed row values.
 *
 * Lets a writer or negotiation test assert the real spreadsheet OpenSpout
 * produced - including each cell's native type (integers as ints, dates as
 * DateTimeInterface, booleans/strings as strings) - by reading the first
 * worksheet back through the OpenSpout reader rather than inspecting bytes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
trait ReadsXlsx
{
    /**
     * Read the first worksheet of the workbook at the given path back as a list
     * of typed row value arrays.
     *
     * @param  string  $path
     * @return list<list<mixed>>
     */
    protected function readWorkbook(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);

        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {

            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }

            break;
        }

        $reader->close();

        return $rows;
    }

    /**
     * Materialise raw XLSX bytes to a temporary file and read them back.
     *
     * @param  string  $bytes
     * @return list<list<mixed>>
     */
    protected function readWorkbookFromString(string $bytes): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'xlsx_read_');

        file_put_contents($path, $bytes);

        try {
            return $this->readWorkbook($path);
        } finally {
            @unlink($path);
        }
    }
}
