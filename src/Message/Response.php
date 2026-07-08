<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Message;

use Hibla\EventLoop\Loop;
use Hibla\HttpServer\Exceptions\JsonEncodingException;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Stream\Interfaces\ReadableStreamInterface;
use Hibla\Stream\Stream;

/**
 * Concrete implementation of an outgoing HTTP Response DTO.
 */
class Response extends AbstractMessage
{
    /**
     * @var callable(ConnectionInterface, string): void|null
     */
    public private(set) mixed $upgradeCallback = null;

    /**
     * @var array<int, string> Map of standard HTTP status codes to reason phrases.
     */
    private const array PHRASES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-status',
        208 => 'Already Reported',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Time-out',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Content Too Large',
        414 => 'Request-URI Too Large',
        415 => 'Unsupported Media Type',
        416 => 'Requested range not satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Time-out',
        505 => 'HTTP Version not supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        511 => 'Network Authentication Required',
    ];

    /**
     * @param int $statusCode
     * @param array<string, string|list<string>> $headers
     * @param string|ReadableStreamInterface $body
     * @param string $reasonPhrase
     * @param string $protocolVersion
     */
    public function __construct(
        public private(set) int $statusCode = 200,
        array $headers = [],
        string|ReadableStreamInterface $body = '',
        public private(set) string $reasonPhrase = '',
        string $protocolVersion = '1.1'
    ) {
        if ($this->reasonPhrase === '') {
            $this->reasonPhrase = self::PHRASES[$this->statusCode] ?? 'Unknown';
        }

        $this->headers = $this->normalizeHeaders($headers);
        $this->body = $body;
        $this->protocolVersion = $protocolVersion;
    }

    /**
     * Helper factory to upgrade the underlying TCP connection for protocols like WebSockets.
     *
     * @param int $status The HTTP status code (e.g., 101 for Switching Protocols).
     * @param array<string, string|list<string>> $headers Headers to send before detaching.
     * @param callable(ConnectionInterface, string): void $onUpgrade Callback executed with the raw connection and any trailing bytes.
     */
    public static function upgrade(int $status, array $headers, callable $onUpgrade): self
    {
        $response = new self($status, $headers, '');
        $response->upgradeCallback = $onUpgrade(...);

        return $response;
    }

    /**
     * Helper factory to build an HTTP redirect response.
     */
    public static function redirect(string $url, int $status = 302): self
    {
        return new self($status, ['Location' => $url]);
    }

    /**
     * Helper factory to build a plain text response.
     */
    public static function plaintext(string $text, int $status = 200): self
    {
        return new self($status, ['content-type' => 'text/plain; charset=utf-8'], $text);
    }

    /**
     * Helper factory to build a JSON response.
     */
    public static function json(mixed $data, int $status = 200): self
    {
        $json = json_encode(
            $data,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION
        );

        if (! \is_string($json)) {
            throw new JsonEncodingException('Unable to encode given data as JSON: ' . json_last_error_msg());
        }

        return new self($status, ['content-type' => 'application/json'], $json . "\n");
    }

    /**
     * Helper factory to build an HTML response.
     */
    public static function html(string $html, int $status = 200): self
    {
        return new self($status, ['content-type' => 'text/html; charset=utf-8'], $html);
    }

    /**
     * Helper factory to build an ergonomic Server-Sent Events (SSE) response.
     *
     * @param callable(SseStream): void $emitter
     */
    public static function sse(callable $emitter): self
    {
        $stream = new SseStream();

        $fiber = new \Fiber(function () use ($emitter, $stream) {
            try {
                $emitter($stream);
            } catch (\Throwable) {
                // Connection dropping unwinds the fiber safely
            } finally {
                $stream->end();
            }
        });

        $stream->setEmitterFiber($fiber);

        Loop::addFiber($fiber);

        return new self(200, [
            'content-type' => 'text/event-stream',
            'cache-control' => 'no-cache',
            'connection' => 'keep-alive',
            'x-accel-buffering' => 'no',
        ], $stream);
    }

    /**
     * Helper factory to build an asynchronous, streaming File Response.
     * Optionally parses Range headers from the Request to support video scrubbing/seeking.
     *
     * @param string $path Local file path to read
     * @param Request|null $request The incoming Request to inspect for "Range" headers
     * @param array<string, string|list<string>> $headers Additional custom headers
     */
    public static function file(string $path, ?Request $request = null, array $headers = []): self
    {
        if (! file_exists($path) || ! is_readable($path)) {
            return self::plaintext('File Not Found', 404);
        }

        $fileSize = (int) filesize($path);
        $contentType = self::detectMimeType($path);
        $stream = Stream::readableFile($path);

        $responseHeaders = [
            'content-type' => $contentType,
            'accept-ranges' => 'bytes',
        ];

        if ($request === null || ! $request->hasHeader('Range')) {
            $responseHeaders['content-length'] = (string) $fileSize;

            return new self(200, [...$responseHeaders, ...$headers], $stream);
        }

        $rangeHeader = $request->getHeaderLine('Range');

        if (preg_match('/bytes=(\d*)-(\d*)/i', $rangeHeader, $matches) !== 1) {
            $responseHeaders['content-length'] = (string) $fileSize;

            return new self(200, [...$responseHeaders, ...$headers], $stream);
        }

        $start = $matches[1] !== '' ? (int) $matches[1] : 0;
        $end = $matches[2] !== '' ? (int) $matches[2] : ($fileSize - 1);

        if ($start >= $fileSize || $end < $start) {
            $responseHeaders['content-length'] = (string) $fileSize;

            return new self(200, [...$responseHeaders, ...$headers], $stream);
        }

        $end = min($end, $fileSize - 1);
        $contentLength = $end - $start + 1;

        $stream->seek($start);

        $bytesRemaining = $contentLength;
        $limiter = Stream::through(function (string $chunk) use (&$bytesRemaining, $stream) {
            if ($bytesRemaining <= 0) {
                $stream->close();

                return '';
            }

            if (\strlen($chunk) >= $bytesRemaining) {
                $chunk = substr($chunk, 0, $bytesRemaining);
                $bytesRemaining = 0;
                $stream->close();

                return $chunk;
            }

            $bytesRemaining -= \strlen($chunk);

            return $chunk;
        });

        $responseHeaders['content-length'] = (string) $contentLength;
        $responseHeaders['content-range'] = "bytes {$start}-{$end}/{$fileSize}";

        $stream->pipe($limiter);

        return new self(
            statusCode: 206,
            headers: [...$responseHeaders, ...$headers],
            body: $limiter
        );
    }

    /**
     * Helper to detect file MIME types safely based on file extension.
     */
    private static function detectMimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $map = [
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'json' => 'application/json; charset=utf-8',
            'html' => 'text/html; charset=utf-8',
        ];

        return $map[$ext] ?? 'application/octet-stream';
    }
}
