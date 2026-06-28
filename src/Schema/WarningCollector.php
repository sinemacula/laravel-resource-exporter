<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Schema;

/**
 * Lenient-mode warning bag.
 *
 * Streaming cannot emit a clean error mid-body, so the lenient strictness mode
 * skips or blanks the offending column or cell and records why here instead of
 * throwing. A caller that wants to surface or log degraded output passes its
 * own collector into the engine and reads it afterwards; preflight mode never
 * adds to it (it fails fast before any bytes). Created per export, so it holds
 * no cross-request state.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class WarningCollector
{
    /** @var list<string> The collected warnings, in the order they occurred */
    private array $warnings = [];

    /**
     * Record a warning.
     *
     * @param  string  $message
     * @return void
     */
    public function add(string $message): void
    {
        $this->warnings[] = $message;
    }

    /**
     * Get every collected warning.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return $this->warnings;
    }

    /**
     * Determine whether any warning was collected.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->warnings === [];
    }
}
