<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Sinks;

use SineMacula\Exporter\Contracts\Sink;
use SineMacula\Exporter\Exceptions\SinkException;

/**
 * Temporary file sink.
 *
 * Lands a fully-written export in a local temporary file whose path the caller
 * can read back. A temp file backs formats that finalise on close (an XLSX is
 * a ZIP), so it is a non-seekable sink: writers finalise locally and hand the
 * file over via putFromFile.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class TempFileSink implements Sink
{
    /** @var string The directory temporary files are created in */
    private readonly string $directory;

    /** @var string|null The resolved temporary file path */
    private ?string $path = null;

    /**
     * Constructor.
     *
     * @param  string|null  $directory
     * @param  string  $prefix
     * @return void
     */
    public function __construct(

        // The directory to create temporary files in, or null for the default.
        ?string $directory = null,

        /** The filename prefix for created temporary files. */
        private readonly string $prefix = 'export_',
    ) {
        $this->directory = $directory ?? sys_get_temp_dir();
    }

    /**
     * Determine whether the sink exposes a seekable stream.
     *
     * @return bool
     */
    #[\Override]
    public function isSeekableStream(): bool
    {
        return false;
    }

    /**
     * Get the underlying stream resource.
     *
     * A temp-file sink backs finalise-on-close formats; use putFromFile.
     *
     * @return never
     *
     * @throws \LogicException
     */
    #[\Override]
    public function stream()
    {
        throw new \LogicException('A temp-file sink is not seekable; finalise the file and use putFromFile().');
    }

    /**
     * Move a fully-written file into the temporary file location.
     *
     * @param  string  $path
     * @return void
     *
     * @throws \SineMacula\Exporter\Exceptions\SinkException
     */
    #[\Override]
    public function putFromFile(string $path): void
    {
        $target = $this->path();

        if (!@rename($path, $target) && !copy($path, $target)) {
            throw new SinkException("Unable to place file [{$path}] into the temp-file sink.");
        }
    }

    /**
     * Get the resolved temporary file path, allocating it on first use.
     *
     * @return string
     *
     * @throws \SineMacula\Exporter\Exceptions\SinkException
     */
    public function path(): string
    {
        if ($this->path === null) {

            $path = tempnam($this->directory, $this->prefix);

            // tempnam() falls back to the system temp directory rather than
            // failing on a bad directory, so this guard cannot be exercised
            // without injecting a filesystem fault.
            // @codeCoverageIgnoreStart
            if ($path === false) {
                throw new SinkException('Unable to allocate a temporary file for the temp-file sink.');
            }
            // @codeCoverageIgnoreEnd
            $this->path = $path;
        }

        return $this->path;
    }
}
