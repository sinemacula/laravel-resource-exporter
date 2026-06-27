<?php

declare(strict_types = 1);

namespace Tests\Support\V3;

use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * A Filesystem disk that does not support signed temporary URLs.
 *
 * Unlike Laravel's FilesystemAdapter it declares no temporaryUrl() method, so
 * method_exists() against it is false - the check the queued export job uses to
 * decide whether to attach a signed download URL. It proxies the one operation
 * the job needs (writeStream, to a real backing disk) and rejects the rest,
 * driving the branch a non-standard disk implementation takes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final readonly class SignerlessDisk implements Filesystem
{
    /**
     * Create a new signer-less disk decorator.
     *
     * @param  \Illuminate\Contracts\Filesystem\Filesystem  $disk
     */
    public function __construct(

        /** The real disk the proxied write delegates to. */
        private Filesystem $disk,
    ) {}

    /**
     * Write a new file using a stream.
     *
     * @param  mixed  $path
     * @param  mixed  $resource
     * @param  array<array-key, mixed>  $options
     * @return bool
     */
    #[\Override]
    public function writeStream(mixed $path, mixed $resource, array $options = []): bool
    {
        return $this->disk->writeStream($path, $resource, $options);
    }

    /**
     * Get the full path for the file at the given path.
     *
     * @param  mixed  $path
     * @return never
     */
    #[\Override]
    public function path(mixed $path): never
    {
        $this->unsupported();
    }

    /**
     * Determine whether a file exists.
     *
     * @param  mixed  $path
     * @return never
     */
    #[\Override]
    public function exists(mixed $path): never
    {
        $this->unsupported();
    }

    /**
     * Get the contents of a file.
     *
     * @param  mixed  $path
     * @return never
     */
    #[\Override]
    public function get(mixed $path): never
    {
        $this->unsupported();
    }

    /**
     * Get a resource to read the file.
     *
     * @param  mixed  $path
     * @return never
     */
    #[\Override]
    public function readStream(mixed $path): never
    {
        $this->unsupported();
    }

    /**
     * Write the contents of a file.
     *
     * @param  mixed  $path
     * @param  mixed  $contents
     * @param  mixed  $options
     * @return never
     */
    #[\Override]
    public function put(mixed $path, mixed $contents, mixed $options = []): never
    {
        $this->unsupported();
    }

    /**
     * Store the uploaded file on the disk.
     *
     * @param  mixed  $path
     * @param  mixed  $file
     * @param  mixed  $options
     * @return never
     */
    #[\Override]
    public function putFile(mixed $path, mixed $file = null, mixed $options = []): never
    {
        $this->unsupported();
    }

    /**
     * Store the uploaded file on the disk with a given name.
     *
     * @param  mixed  $path
     * @param  mixed  $file
     * @param  mixed  $name
     * @param  mixed  $options
     * @return never
     */
    #[\Override]
    public function putFileAs(mixed $path, mixed $file, mixed $name = null, mixed $options = []): never
    {
        $this->unsupported();
    }

    /**
     * Get the visibility for the given path.
     *
     * @param  mixed  $path
     * @return never
     */
    #[\Override]
    public function getVisibility(mixed $path): never
    {
        $this->unsupported();
    }

    /**
     * Set the visibility for the given path.
     *
     * @param  mixed  $path
     * @param  mixed  $visibility
     * @return never
     */
    #[\Override]
    public function setVisibility(mixed $path, mixed $visibility): never
    {
        $this->unsupported();
    }

    /**
     * Prepend to a file.
     *
     * @param  mixed  $path
     * @param  mixed  $data
     * @return never
     */
    #[\Override]
    public function prepend(mixed $path, mixed $data): never
    {
        $this->unsupported();
    }

    /**
     * Append to a file.
     *
     * @param  mixed  $path
     * @param  mixed  $data
     * @return never
     */
    #[\Override]
    public function append(mixed $path, mixed $data): never
    {
        $this->unsupported();
    }

    /**
     * Delete the file at a given path.
     *
     * @param  mixed  $paths
     * @return never
     */
    #[\Override]
    public function delete(mixed $paths): never
    {
        $this->unsupported();
    }

    /**
     * Copy a file to a new location.
     *
     * @param  mixed  $from
     * @param  mixed  $to
     * @return never
     */
    #[\Override]
    public function copy(mixed $from, mixed $to): never
    {
        $this->unsupported();
    }

    /**
     * Move a file to a new location.
     *
     * @param  mixed  $from
     * @param  mixed  $to
     * @return never
     */
    #[\Override]
    public function move(mixed $from, mixed $to): never
    {
        $this->unsupported();
    }

    /**
     * Get the file size of a given file.
     *
     * @param  mixed  $path
     * @return never
     */
    #[\Override]
    public function size(mixed $path): never
    {
        $this->unsupported();
    }

    /**
     * Get the file's last modification time.
     *
     * @param  mixed  $path
     * @return never
     */
    #[\Override]
    public function lastModified(mixed $path): never
    {
        $this->unsupported();
    }

    /**
     * Get an array of all files in a directory.
     *
     * @param  mixed  $directory
     * @param  mixed  $recursive
     * @return never
     */
    #[\Override]
    public function files(mixed $directory = null, mixed $recursive = false): never
    {
        $this->unsupported();
    }

    /**
     * Get all of the files from the given directory (recursive).
     *
     * @param  mixed  $directory
     * @return never
     */
    #[\Override]
    public function allFiles(mixed $directory = null): never
    {
        $this->unsupported();
    }

    /**
     * Get all of the directories within a given directory.
     *
     * @param  mixed  $directory
     * @param  mixed  $recursive
     * @return never
     */
    #[\Override]
    public function directories(mixed $directory = null, mixed $recursive = false): never
    {
        $this->unsupported();
    }

    /**
     * Get all the directories within a given directory (recursive).
     *
     * @param  mixed  $directory
     * @return never
     */
    #[\Override]
    public function allDirectories(mixed $directory = null): never
    {
        $this->unsupported();
    }

    /**
     * Create a directory.
     *
     * @param  mixed  $path
     * @return never
     */
    #[\Override]
    public function makeDirectory(mixed $path): never
    {
        $this->unsupported();
    }

    /**
     * Recursively delete a directory.
     *
     * @param  mixed  $directory
     * @return never
     */
    #[\Override]
    public function deleteDirectory(mixed $directory): never
    {
        $this->unsupported();
    }

    /**
     * Reject an operation this double does not implement.
     *
     * @return never
     *
     * @throws \BadMethodCallException
     */
    private function unsupported(): never
    {
        throw new \BadMethodCallException('The signer-less disk double only supports writeStream().');
    }
}
