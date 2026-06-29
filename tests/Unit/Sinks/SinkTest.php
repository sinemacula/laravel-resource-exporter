<?php

declare(strict_types = 1);

namespace Tests\Unit\Sinks;

use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Exporter\Contracts\Sink;
use SineMacula\Exporter\Exceptions\SinkException;
use SineMacula\Exporter\Sinks\DiskSink;
use SineMacula\Exporter\Sinks\StreamedResponseSink;
use SineMacula\Exporter\Sinks\StreamSink;
use SineMacula\Exporter\Sinks\StringSink;
use SineMacula\Exporter\Sinks\TempFileSink;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\ExporterTestCase;

/**
 * Tests the five export sinks.
 *
 * A sink is the byte destination a writer streams into. The seekable sinks
 * (string buffer, raw stream, streamed response) expose a stream a writer rows
 * through; the non-seekable sinks (storage disk, temp file) take a finalised
 * file via putFromFile, the path an XLSX-style finalise-on-close format needs.
 * These tests exercise each sink's contract directly, including the seekability
 * flag, the finalise-then-upload path and the open-failure guards.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(StringSink::class)]
#[CoversClass(StreamSink::class)]
#[CoversClass(StreamedResponseSink::class)]
#[CoversClass(DiskSink::class)]
#[CoversClass(TempFileSink::class)]
final class SinkTest extends ExporterTestCase
{
    /**
     * It buffers written bytes and reads them back as a string.
     *
     * @return void
     */
    public function testStringSinkBuffersAndReadsBack(): void
    {
        $sink = new StringSink;

        self::assertTrue($sink->isSeekableStream());

        fwrite($sink->stream(), 'hello ');
        fwrite($sink->stream(), 'world');

        self::assertSame('hello world', $sink->contents());
    }

    /**
     * It copies a finalised file into the string buffer.
     *
     * @return void
     */
    public function testStringSinkCopiesFromFile(): void
    {
        $file = $this->makeFile('from-file');

        $sink = new StringSink;
        $sink->putFromFile($file);

        self::assertSame('from-file', $sink->contents());
    }

    /**
     * It raises a sink exception when a file cannot be opened.
     *
     * @return void
     */
    public function testStringSinkThrowsWhenFileIsUnreadable(): void
    {
        $this->assertPutFromFileThrows(new StringSink);
    }

    /**
     * It streams written bytes through an injected resource.
     *
     * @return void
     */
    public function testStreamSinkWritesThroughTheGivenResource(): void
    {
        $resource = fopen('php://memory', 'r+b');
        self::assertIsResource($resource);

        $sink = new StreamSink($resource);

        self::assertTrue($sink->isSeekableStream());
        self::assertSame($resource, $sink->stream());

        fwrite($sink->stream(), 'payload');
        rewind($resource);

        self::assertSame('payload', stream_get_contents($resource));
    }

    /**
     * It rejects a non-resource argument.
     *
     * @return void
     */
    public function testStreamSinkRejectsANonResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new StreamSink('not-a-resource');
    }

    /**
     * It opens php://output by default and copies a file through it.
     *
     * @return void
     */
    public function testStreamSinkDefaultsToOutputAndCopiesFromFile(): void
    {
        $file = $this->makeFile('streamed');

        ob_start();
        (new StreamSink)->putFromFile($file);
        $output = (string) ob_get_clean();

        self::assertSame('streamed', $output);
    }

    /**
     * It raises a sink exception when a file cannot be opened.
     *
     * @return void
     */
    public function testStreamSinkThrowsWhenFileIsUnreadable(): void
    {
        ob_start();

        try {
            $this->assertPutFromFileThrows(new StreamSink);
        } finally {
            ob_end_clean();
        }
    }

    /**
     * It drives a producer over a streamed response at the right status.
     *
     * @return void
     */
    public function testStreamedResponseSinkProducesAResponse(): void
    {
        $sink     = new StreamedResponseSink;
        $response = $sink->toResponse(static function ($passed) use ($sink): void {
            self::assertSame($sink, $passed);

            fwrite($passed->stream(), 'streamed-body');
        }, 207, ['X-Test' => 'yes']);

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame(207, $response->getStatusCode());
        self::assertSame('yes', $response->headers->get('X-Test'));
        self::assertTrue($sink->isSeekableStream());

        self::assertSame('streamed-body', $this->streamToString($response));
    }

    /**
     * It copies a finalised file into the response stream.
     *
     * @return void
     */
    public function testStreamedResponseSinkCopiesFromFile(): void
    {
        $file = $this->makeFile('response-file');

        ob_start();
        (new StreamedResponseSink)->putFromFile($file);
        $output = (string) ob_get_clean();

        self::assertSame('response-file', $output);
    }

    /**
     * It raises a sink exception when a file cannot be opened.
     *
     * @return void
     */
    public function testStreamedResponseSinkThrowsWhenFileIsUnreadable(): void
    {
        ob_start();

        try {
            $this->assertPutFromFileThrows(new StreamedResponseSink);
        } finally {
            ob_end_clean();
        }
    }

    /**
     * It uploads a finalised file to a storage disk via a stream.
     *
     * @return void
     */
    public function testDiskSinkUploadsToStorage(): void
    {
        Storage::fake('exports');

        $disk = Storage::disk('exports');
        $file = $this->makeFile('disk-payload');

        $sink = new DiskSink($disk, 'reports/users.csv');

        self::assertFalse($sink->isSeekableStream());
        self::assertSame('reports/users.csv', $sink->path());

        $sink->putFromFile($file);

        self::assertSame('disk-payload', $disk->get('reports/users.csv'));
    }

    /**
     * It refuses to expose a stream because a disk is not seekable.
     *
     * @return void
     */
    public function testDiskSinkStreamIsRejected(): void
    {
        $this->expectException(\LogicException::class);

        (new DiskSink(Storage::fake('exports'), 'x.csv'))->stream();
    }

    /**
     * It raises a sink exception when a file cannot be opened.
     *
     * @return void
     */
    public function testDiskSinkThrowsWhenFileIsUnreadable(): void
    {
        $this->assertPutFromFileThrows(new DiskSink(Storage::fake('exports'), 'x.csv'));
    }

    /**
     * It lands a finalised file at an allocated temporary path.
     *
     * @return void
     */
    public function testTempFileSinkLandsTheFile(): void
    {
        $file = $this->makeFile('temp-payload');

        $sink = new TempFileSink;

        self::assertFalse($sink->isSeekableStream());

        $path = $sink->path();

        self::assertSame($path, $sink->path(), 'The temp path is allocated once and reused.');

        $sink->putFromFile($file);

        self::assertSame('temp-payload', (string) file_get_contents($path));

        @unlink($path);
    }

    /**
     * It refuses to expose a stream because a temp file is finalise-on-close.
     *
     * @return void
     */
    public function testTempFileSinkStreamIsRejected(): void
    {
        $this->expectException(\LogicException::class);

        (new TempFileSink)->stream();
    }

    /**
     * It raises a sink exception when the source file cannot be placed.
     *
     * @return void
     */
    public function testTempFileSinkThrowsWhenSourceIsMissing(): void
    {
        $sink = new TempFileSink;

        $this->withSuppressedWarnings(function () use ($sink): void {
            $this->expectException(SinkException::class);

            $sink->putFromFile('/no/such/source/file.tmp');
        });
    }

    /**
     * Write a throwaway file with the given contents and return its path.
     *
     * @param  string  $contents
     * @return string
     */
    private function makeFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sink_src_');

        self::assertIsString($path);

        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Assert that putFromFile raises a sink exception for an unreadable path.
     *
     * @param  \SineMacula\Exporter\Contracts\Sink  $sink
     * @return void
     */
    private function assertPutFromFileThrows(Sink $sink): void
    {
        $this->withSuppressedWarnings(function () use ($sink): void {
            $this->expectException(SinkException::class);

            $sink->putFromFile('/no/such/file/anywhere.bin');
        });
    }

    /**
     * Run a callback with PHP warnings suppressed so a guard's throw surfaces.
     *
     * @param  \Closure(): void  $callback
     * @return void
     */
    private function withSuppressedWarnings(\Closure $callback): void
    {
        set_error_handler(static fn (): bool => true);

        try {
            $callback();
        } finally {
            restore_error_handler();
        }
    }
}
