<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Contracts;

/**
 * Sink contract.
 *
 * The destination bytes are written to (string buffer, php://output, a
 * StreamedResponse, or a storage disk / temp file). Seekable stream sinks
 * support direct streaming; non-seekable sinks require the finalise-then-upload
 * path (an XLSX is a ZIP closed on finalisation, and remote disks cannot be
 * streamed to in place).
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface Sink
{
    /**
     * Determine whether the sink exposes a seekable stream that a writer can
     * stream into directly.
     *
     * @return bool
     */
    public function isSeekableStream(): bool;

    /**
     * Get the underlying stream resource for streaming writers.
     *
     * @return resource
     */
    public function stream(); // phpcs:ignore SineMaculaLaravel.TypeHints.ReturnTypeHint.MissingNativeTypeHint

    /**
     * Place a fully-written file into the sink (finalise-then-upload path for
     * non-seekable sinks and formats that cannot stream in place).
     *
     * @param  string  $path
     * @return void
     */
    public function putFromFile(string $path): void;
}
