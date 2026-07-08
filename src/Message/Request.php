<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Message;

use Hibla\EventLoop\Loop;
use Hibla\HttpServer\Exceptions\MalformedMultipartException;
use Hibla\HttpServer\Exceptions\MessageParsingException;
use Hibla\HttpServer\Exceptions\MultipartException;
use Hibla\HttpServer\Exceptions\PayloadTooLargeException;
use Hibla\HttpServer\Exceptions\StreamTransferException;
use Hibla\HttpServer\Internals\MultipartParser;
use Hibla\HttpServer\Traits\DeletesFilesSafely;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Stream\Interfaces\PromiseReadableStreamInterface;
use Hibla\Stream\Interfaces\ReadableStreamInterface;
use Hibla\Stream\Stream;

/**
 * Concrete implementation of an incoming HTTP Request Value Object.
 */
class Request extends AbstractMessage
{
    use DeletesFilesSafely;

    /**
     * @internal Inherited from the HTTP Server configuration
     */
    public int $maxBodySize = 10485760;

    /**
     * @internal Inherited from the HTTP Server configuration
     */
    public int $maxHeaderSize = 16384;

    /**
     * @internal Inherited from the HTTP Server configuration
     */
    public int $maxUploadedFiles = 20;

    /**
     * @internal Inherited from the HTTP Server configuration
     */
    public int $maxFormFields = 1000;

    /**
     * Used to memoize the buffered body so multiple calls don't exhaust the stream.
     */
    private ?string $cachedBody = null;

    /**
     * @param string $method
     * @param string $uri
     * @param array<string, string|list<string>> $headers
     * @param string|ReadableStreamInterface $body
     * @param string $protocolVersion
     * @param array<string, mixed> $serverParams
     */
    public function __construct(
        public private(set) string $method,
        public private(set) string $uri,
        array $headers = [],
        string|ReadableStreamInterface $body = '',
        string $protocolVersion = '1.1',
        public private(set) array $serverParams = []
    ) {
        $this->headers = $this->normalizeHeaders($headers);
        $this->body = $body;
        $this->protocolVersion = $protocolVersion;
    }

    /**
     * Asynchronously buffers the request body stream into memory and returns it as a string.
     *
     * @param int|null $maxBytes Optional override for the maximum allowed body size.
     *
     * @return PromiseInterface<string>
     */
    public function getBufferedBody(?int $maxBytes = null): PromiseInterface
    {
        if ($this->cachedBody !== null) {
            return Promise::resolved($this->cachedBody);
        }

        $body = $this->body;
        if (\is_string($body)) {
            $this->cachedBody = $body;

            return Promise::resolved($body);
        }

        $limit = $maxBytes ?? $this->maxBodySize;

        $contentLength = $this->getHeaderLine('Content-Length');

        if ($contentLength !== '' && ctype_digit($contentLength)) {
            if ((int) $contentLength > $limit) {
                $body->close();

                return Promise::rejected(new PayloadTooLargeException('Content Too Large: Exceeded ' . $limit . ' bytes.'));
            }
        }

        if (! $body->isReadable()) {
            $this->cachedBody = '';

            return Promise::resolved('');
        }

        /** @var Promise<string> */
        return new Promise(function (callable $resolve, callable $reject, callable $onCancel) use ($body, $limit) {
            $buffer = '';

            /** @var (\Closure(string): void)|null $dataListener */
            $dataListener = null;
            /** @var (\Closure(): void)|null $endListener */
            $endListener = null;
            /** @var (\Closure(\Throwable): void)|null $errorListener */
            $errorListener = null;

            $cleanup = function () use ($body, &$dataListener, &$endListener, &$errorListener) {
                if ($dataListener !== null) {
                    $body->removeListener('data', $dataListener);
                }
                if ($endListener !== null) {
                    $body->removeListener('end', $endListener);
                }
                if ($errorListener !== null) {
                    $body->removeListener('error', $errorListener);
                }
            };

            $dataListener = function (string $chunk) use (&$buffer, $limit, $body, $reject, $cleanup) {
                $buffer .= $chunk;
                if (\strlen($buffer) > $limit) {
                    $cleanup();
                    $body->close();
                    $reject(new PayloadTooLargeException('Content Too Large: Exceeded ' . $limit . ' bytes.'));
                }
            };

            $endListener = function () use (&$buffer, $resolve, $cleanup) {
                $cleanup();
                $this->cachedBody = $buffer;
                $resolve($buffer);
            };

            $errorListener = function (\Throwable $e) use ($reject, $cleanup) {
                $cleanup();
                $reject($e);
            };

            $onCancel(function () use ($cleanup, $body) {
                $cleanup();
                $body->close();
            });

            $body->on('data', $dataListener);
            $body->on('end', $endListener);
            $body->on('error', $errorListener);

            $body->resume();
        });
    }

    /**
     * Asynchronously buffers the request body and decodes it as JSON.
     *
     * @param int|null $maxBytes Optional override for the maximum allowed body size.
     *
     * @return PromiseInterface<mixed>
     */
    public function getJson(?int $maxBytes = null): PromiseInterface
    {
        $promise = $this->getBufferedBody($maxBytes)->then(function (string $body) {
            if ($body === '') {
                return null;
            }

            $decoded = json_decode($body, true);
            if (json_last_error() !== \JSON_ERROR_NONE) {
                throw new MessageParsingException('Invalid JSON payload: ' . json_last_error_msg());
            }

            return $decoded;
        });

        return Promise::propagateCancellation($promise);
    }

    /**
     * Parses the request body as multipart/form-data.
     * Operates purely via event-driven stream pipes and promise chaining.
     *
     * @return PromiseInterface<MultipartForm>
     */
    public function getParsedBody(): PromiseInterface
    {
        $contentType = $this->getHeaderLine('Content-Type');

        if (preg_match('/boundary=(?:"([^"]+)"|([^;,\s]+))/i', $contentType, $matches) !== 1) {
            return Promise::rejected(new MalformedMultipartException('Not a valid multipart/form-data request'));
        }

        $boundary = $matches[1] !== '' ? $matches[1] : $matches[2];

        /** @var Promise<MultipartForm> */
        return new Promise(function (callable $resolve, callable $reject, callable $onCancel) use ($boundary) {
            $parser = new MultipartParser($boundary, $this->maxHeaderSize);
            $form = new MultipartForm();

            /** @var array<int, PromiseInterface<null>> $writePromises */
            $writePromises = [];

            $fileCount = 0;
            $fieldCount = 0;
            $maxFiles = $this->maxUploadedFiles;
            $maxFields = $this->maxFormFields;

            $parser->on('field', function (mixed $name, mixed $value) use ($form, &$fieldCount, $maxFields, $parser) {
                if (++$fieldCount > $maxFields) {
                    $parser->emit('error', [new MultipartException('Too many form fields in multipart body')]);

                    return;
                }

                if (\is_string($name) && \is_string($value)) {
                    $form->addField($name, $value);
                }
            });

            $parser->on('file', function (mixed $name, mixed $filename, mixed $mime, mixed $fileStream) use ($form, &$writePromises, &$fileCount, $maxFiles, $parser) {
                if (++$fileCount > $maxFiles) {
                    $parser->emit('error', [new MultipartException('Too many file uploads in multipart body')]);

                    return;
                }

                if (! \is_string($name) || ! \is_string($filename) || ! \is_string($mime) || ! $fileStream instanceof ReadableStreamInterface) {
                    return;
                }

                $tmpPath = tempnam(sys_get_temp_dir(), 'hibla_up_');
                if ($tmpPath === false) {
                    return;
                }

                $destination = Stream::writableFile($tmpPath);

                /** @var Promise<null> $writePromise */
                $writePromise = new Promise(function ($res, $rej, $onFileCancel) use ($fileStream, $destination, $tmpPath, $form, $name, $filename, $mime) {
                    $bytesWritten = 0;

                    $fileStream->on('data', function (mixed $chunk) use (&$bytesWritten) {
                        if (\is_string($chunk)) {
                            $bytesWritten += \strlen($chunk);
                        }
                    });

                    $destination->on('finish', function () use ($res, $form, $name, $filename, $mime, $tmpPath, &$bytesWritten) {
                        $form->addFile($name, new UploadedFile($tmpPath, $filename, $mime, $bytesWritten));
                        $res(null);
                    });

                    $destination->on('error', function (mixed $e) use ($rej, $tmpPath) {
                        self::deleteFileSafely($tmpPath);
                        $rej($e instanceof \Throwable ? $e : new MalformedMultipartException('Write error'));
                    });

                    $fileStream->on('error', function (mixed $e) use ($rej, $destination, $tmpPath) {
                        $destination->close();
                        self::deleteFileSafely($tmpPath);
                        $rej($e instanceof \Throwable ? $e : new MultipartException('Stream error'));
                    });

                    $onFileCancel(static function () use ($fileStream, $destination, $tmpPath) {
                        $fileStream->close();
                        $destination->close();
                        self::deleteFileSafely($tmpPath);
                    });

                    $fileStream->pipe($destination);
                });

                $writePromises[] = $writePromise;
            });

            $body = $this->body;
            $isStream = $body instanceof ReadableStreamInterface;

            /** @var Promise<null> $parserPromise */
            $parserPromise = new Promise(function (callable $res, callable $rej) use ($parser, $isStream, $body) {
                $parser->on('end', static function () use ($res) {
                    $res(null);
                });

                $parser->on('error', static function (mixed $e) use ($rej) {
                    $rej($e instanceof \Throwable ? $e : new MultipartException('Parser error'));
                });

                if ($isStream && $body instanceof ReadableStreamInterface) {
                    $body->on('error', static function (mixed $e) use ($rej) {
                        $rej($e instanceof \Throwable ? $e : new StreamTransferException('Body stream error'));
                    });
                }
            });

            $onCancel(static function () use (&$writePromises, $parserPromise, $isStream, $body, $parser) {
                foreach ($writePromises as $p) {
                    $p->cancel();
                }

                $parserPromise->cancel();
                $parser->close();

                if ($isStream && $body instanceof ReadableStreamInterface) {
                    $body->close();
                }
            });

            $parserPromise->then(static function () use (&$writePromises) {
                if (\count($writePromises) === 0) {
                    /** @var PromiseInterface<null> $nullPromise */
                    $nullPromise = Promise::resolved(null);

                    return $nullPromise;
                }

                /** @var PromiseInterface<array<int|string, null>> $allPromise */
                $allPromise = Promise::all($writePromises);

                return $allPromise;
            })->then(static function () use ($resolve, $form) {
                $resolve($form);
            })->catch($reject(...));

            if ($isStream && $body instanceof ReadableStreamInterface) {
                $body->pipe($parser);
            } elseif (\is_string($body)) {
                $parser->write($body);
                $parser->end();
            }
        });
    }

    /**
     * Streams the multipart request body on-the-fly, invoking callbacks for each field and file part.
     * Prevents any local disk I/O, allowing developers to stream file uploads directly to S3/Object Storage.
     *
     * @param callable(string $name, string $filename, string $mime, PromiseReadableStreamInterface $fileStream): void $onFile
     * @param (callable(string $name, string $value): void)|null $onField
     *
     * @return PromiseInterface<void>
     */
    public function streamMultipart(callable $onFile, ?callable $onField = null): PromiseInterface
    {
        $contentType = $this->getHeaderLine('Content-Type');

        if (preg_match('/boundary=(?:"([^"]+)"|([^;,\s]+))/i', $contentType, $matches) !== 1) {
            return Promise::rejected(new MalformedMultipartException('Not a valid multipart/form-data request'));
        }

        $boundary = $matches[1] !== '' ? $matches[1] : $matches[2];
        $body = $this->body;

        /** @var Promise<void> */
        return new Promise(function (callable $resolve, callable $reject, callable $onCancel) use ($boundary, $onFile, $onField, $body) {
            $parser = new MultipartParser($boundary);

            $state = new class () {
                public int $pendingFibers = 0;

                public bool $parserEnded = false;
            };

            $parser->on('file', function (string $name, string $filename, string $mime, PromiseReadableStreamInterface $fileStream) use ($onFile, $state, $resolve, $reject): void {
                $state->pendingFibers++;

                $fiber = new \Fiber(function () use ($onFile, $name, $filename, $mime, $fileStream, $state, $resolve, $reject): void {
                    try {
                        $onFile($name, $filename, $mime, $fileStream);
                    } catch (\Throwable $e) {
                        $reject($e);
                    } finally {
                        $state->pendingFibers--;
                        if ($state->pendingFibers === 0 && $state->parserEnded) {
                            $resolve(null);
                        }
                    }
                });

                Loop::addFiber($fiber);
            });

            if ($onField !== null) {
                $parser->on('field', function (string $name, string $value) use ($onField, $state, $resolve, $reject): void {
                    $state->pendingFibers++;

                    $fiber = new \Fiber(function () use ($onField, $name, $value, $state, $resolve, $reject): void {
                        try {
                            $onField($name, $value);
                        } catch (\Throwable $e) {
                            $reject($e);
                        } finally {
                            $state->pendingFibers--;
                            if ($state->pendingFibers === 0 && $state->parserEnded) {
                                $resolve(null);
                            }
                        }
                    });

                    Loop::addFiber($fiber);
                });
            }

            $parser->on('end', function () use ($state, $resolve): void {
                $state->parserEnded = true;
                if ($state->pendingFibers === 0) {
                    $resolve(null);
                }
            });

            $parser->on('error', $reject(...));

            if ($body instanceof ReadableStreamInterface) {
                $body->on('error', $reject(...));

                $onCancel(static function () use ($body, $parser): void {
                    $body->close();
                    $parser->close();
                });

                $body->pipe($parser);
            } else {
                try {
                    $parser->write($body);
                    $parser->end();
                } catch (\Throwable $e) {
                    $reject($e);
                }
            }
        });
    }
}