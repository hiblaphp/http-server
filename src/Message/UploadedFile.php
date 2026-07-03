<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Message;

use Hibla\HttpServer\Traits\DeletesFilesSafely;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Stream\Stream;

final class UploadedFile
{
    use DeletesFilesSafely;

    private bool $moved = false;

    /**
     * @param string $tmpPath Server-side temporary path where the upload is buffered.
     * @param string $clientFilename Client-supplied original filename, with directory
     *   path information stripped (RFC 7578 section 4.2 / RFC 2183 section 2.3). The remaining name
     *   and extension are still attacker-controlled and unverified -- do not use
     *   directly as a storage path or as a basis for trust decisions (e.g. assuming
     *   a ".jpg" extension means the content is actually an image).
     * @param string $clientMediaType Client-supplied MIME type from the part's
     *   Content-Type header. This is an unverified assertion, not a detected type --
     *   it is trivially spoofable and must not be relied on for security decisions.
     *   Use content-based type detection (e.g. finfo / magic bytes) if the actual
     *   file type matters.
     * @param int $size Size in bytes as counted while streaming the upload to disk.
     */
    public function __construct(
        public readonly string $tmpPath,
        public readonly string $clientFilename,
        public readonly string $clientMediaType,
        public readonly int $size
    ) {}

    /**
     * Asynchronously moves the uploaded file using standard streams and pure promise events.
     *
     * @return PromiseInterface<void>
     */
    public function moveTo(string $destinationPath): PromiseInterface
    {
        if ($this->moved) {
            return Promise::rejected(new \RuntimeException('File has already been moved.'));
        }

        if (! file_exists($this->tmpPath)) {
            return Promise::rejected(new \RuntimeException('Temporary file no longer exists.'));
        }

        /** @var Promise<void> */
        return new Promise(function (callable $resolve, callable $reject, callable $onCancel) use ($destinationPath) {
            try {
                $source = Stream::readableFile($this->tmpPath);
                $dest = Stream::writableFile($destinationPath);
            } catch (\Throwable $e) {
                if (isset($source)) {
                    $source->close();
                }

                $reject($e);

                return;
            }

            $dest->on('finish', function () use ($resolve, $source) {
                $source->close();
                $this->moved = true;
                self::deleteFileSafely($this->tmpPath);
                $resolve(null);
            });

            $dest->on('error', function (\Throwable $e) use ($reject, $source, $destinationPath) {
                $source->close();
                self::deleteFileSafely($destinationPath);
                $reject($e);
            });

            $source->on('error', function (\Throwable $e) use ($reject, $dest, $destinationPath) {
                $dest->close();
                self::deleteFileSafely($destinationPath);
                $reject($e);
            });

            $onCancel(static function () use ($source, $dest, $destinationPath) {
                $source->close();
                $dest->close();

                if (file_exists($destinationPath)) {
                    unlink($destinationPath);
                }
            });

            $source->pipe($dest);
        });
    }

    public function __destruct()
    {
        if (! $this->moved) {
            self::deleteFileSafely($this->tmpPath);
        }
    }
}
