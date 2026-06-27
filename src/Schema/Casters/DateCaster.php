<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Schema\Casters;

use SineMacula\Exporter\Schema\CellValue;
use SineMacula\Exporter\Schema\Contracts\Caster;
use SineMacula\Exporter\Schema\Enums\CellType;

/**
 * Date / date-time caster.
 *
 * Normalises a date-like value (a DateTimeInterface, a parseable string, or a
 * UNIX timestamp) into an immutable date carried natively on the cell, with the
 * display pattern kept as the format hint. One class serves both the `date` and
 * `dateTime` casts: the cell type and default pattern are injected per
 * registration, so a typed writer emits a native date cell while a textual
 * writer renders the value through the hinted pattern.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class DateCaster implements Caster
{
    /**
     * Create a new date caster.
     *
     * @param  \SineMacula\Exporter\Schema\Enums\CellType  $type
     * @param  string  $defaultFormat
     */
    public function __construct(

        /** The native cell type emitted for a parsed date. */
        private CellType $type = CellType::DATE,

        /** The default display pattern when none is supplied. */
        private string $defaultFormat = 'Y-m-d',
    ) {}

    /**
     * Cast a raw value into a typed date cell.
     *
     * @param  mixed  $value
     * @param  array<string, mixed>  $options
     * @return \SineMacula\Exporter\Schema\CellValue
     */
    #[\Override]
    public function cast(mixed $value, array $options = []): CellValue
    {
        $date = $this->toDate($value);

        if ($date === null) {
            return new CellValue(null, CellType::NULL);
        }

        $timezone = $options['timezone'] ?? null;

        if (is_string($timezone) && $timezone !== '') {
            $date = $date->setTimezone(new \DateTimeZone($timezone));
        }

        $format = $options['format'] ?? $this->defaultFormat;

        return new CellValue($date, $this->type, is_string($format) ? $format : $this->defaultFormat);
    }

    /**
     * Resolve the given value to an immutable date, or null when unparseable.
     *
     * @param  mixed  $value
     * @return \DateTimeImmutable|null
     */
    private function toDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (new \DateTimeImmutable)->setTimestamp((int) $value);
        }

        return $this->parseString($value);
    }

    /**
     * Parse a non-empty date string, or null when it is unparseable.
     *
     * @param  mixed  $value
     * @return \DateTimeImmutable|null
     */
    private function parseString(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
