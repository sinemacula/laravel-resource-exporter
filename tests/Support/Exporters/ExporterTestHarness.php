<?php

declare(strict_types = 1);

namespace Tests\Support\Exporters;

use SineMacula\Exporter\Exporters\Exporter as BaseExporter;

/**
 * Test harness for protected base exporter behavior.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class ExporterTestHarness extends BaseExporter
{
    /** @var array<string, mixed> */
    protected const array DEFAULT_CONFIG = [
        'delimiter' => ';',
        'enclosure' => '"',
    ];

    /**
     * Expose ignored fields for assertions.
     *
     * @return array<int, string>
     */
    public function ignoredFields(): array
    {
        return $this->ignored;
    }

    /**
     * Expose protected stringable checks.
     *
     * @param  mixed  $value
     * @return bool
     */
    public function exposeIsStringable(mixed $value): bool
    {
        return $this->isStringable($value);
    }
}
