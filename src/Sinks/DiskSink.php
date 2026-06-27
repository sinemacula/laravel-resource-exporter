<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Sinks;

use Illuminate\Contracts\Filesystem\Filesystem;
use SineMacula\Exporter\Contracts\Sink;
use SineMacula\Exporter\Exceptions\SinkException;

/**
 * Storage disk sink.
 *
 * Places a fully-written file onto a Laravel Storage disk at a given path.
 * Remote disks cannot be streamed to in place, so this is a non-seekable
 * sink: writers finalise locally and hand the file over via putFromFile,
 * which uploads it as a stream at constant memory.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class DiskSink implements Sink
{
    /**
     * Constructor.
     *
     * @param  \Illuminate\Contracts\Filesystem\Filesystem  $disk
     * @param  string  $path
     * @param  array<string, mixed>  $options
     * @return void
     */
    public function __construct(

        /** The storage disk the file is uploaded to. */
        private readonly Filesystem $disk,

        /** The destination path on the disk. */
        private readonly string $path,

        /** The write options forwarded to the disk. */
        private readonly array $options = [],
    ) {}

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
     * A storage disk cannot be streamed to in place; use putFromFile instead.
     *
     * @return never
     *
     * @throws \LogicException
     */
    #[\Override]
    public function stream()
    {
        throw new \LogicException('A disk sink is not seekable; finalise the file and use putFromFile().');
    }

    /**
     * Upload a fully-written file to the storage disk.
     *
     * @param  string  $path
     * @return void
     *
     * @throws \SineMacula\Exporter\Exceptions\SinkException
     */
    #[\Override]
    public function putFromFile(string $path): void
    {
        $source = fopen($path, 'rb');

        if ($source === false) {
            throw new SinkException("Unable to open file [{$path}] for the disk sink.");
        }

        try {
            $this->disk->writeStream($this->path, $source, $this->options);
        } finally {
            fclose($source);
        }
    }

    /**
     * Get the destination path on the disk.
     *
     * @return string
     */
    public function path(): string
    {
        return $this->path;
    }
}
