<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Internals;

use Evenement\EventEmitter;
use Hibla\HttpServer\Exceptions\MultipartPartTooLargeException;
use Hibla\Stream\Interfaces\WritableStreamInterface;
use Hibla\Stream\ThroughStream;

/**
 * @internal
 *
 * High-performance, streaming multipart/form-data parser.
 * Operates purely in-memory with a bounded sliding window.
 */
class MultipartParser extends EventEmitter implements WritableStreamInterface
{
    private const int STATE_PREAMBLE = 0;

    private const int STATE_BOUNDARY_SUFFIX = 1;

    private const int STATE_HEADERS = 2;

    private const int STATE_BODY = 3;

    private const int STATE_EPILOGUE = 4;

    private int $state = self::STATE_PREAMBLE;

    private string $buffer = '';

    private string $boundary;

    private bool $writable = true;

    private ?string $currentName = null;

    private ?string $currentFilename = null;

    private ?string $currentMime = null;

    private ?string $currentFieldBuffer = null;

    private ?ThroughStream $currentFileStream = null;

    /**
     * Whether the current part has Content-Disposition: form-data, per
     * RFC 7578 section 4.2 ("Each part MUST contain a Content-Disposition header
     * field where the disposition type is 'form-data'."). Parts that fail
     * this check are parsed structurally (so boundary tracking stays
     * correct) but are never emitted as fields or files.
     */
    private bool $currentValid = false;

    public function __construct(string $boundary, private readonly int $maxHeaderSize = 16384)
    {
        $this->boundary = $boundary;
    }

    /**
     * {@inheritDoc}
     */
    public function write(string $data): bool
    {
        if (! $this->writable) {
            return false;
        }

        $this->buffer .= $data;
        $this->parseBuffer();

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function end(?string $data = null): void
    {
        if ($data !== null && $data !== '') {
            $this->write($data);
        }

        $this->writable = false;

        if ($this->currentFileStream !== null) {
            $this->currentFileStream->end();
        }

        $this->emit('close');
    }

    /**
     * {@inheritDoc}
     */
    public function isWritable(): bool
    {
        return $this->writable;
    }

    /**
     * {@inheritDoc}
     */
    public function close(): void
    {
        if (! $this->writable) {
            return;
        }

        $this->writable = false;

        // Forcefully close the active file stream to abort any in-flight uploads on cancellation
        if ($this->currentFileStream !== null) {
            $this->currentFileStream->close();
            $this->currentFileStream = null;
        }
    }

    private function parseBuffer(): void
    {
        while (\strlen($this->buffer) > 0) {

            if ($this->state === self::STATE_PREAMBLE) {
                $boundary = '--' . $this->boundary;
                $pos = strpos($this->buffer, $boundary);

                if ($pos !== false) {
                    $this->buffer = substr($this->buffer, $pos + \strlen($boundary));
                    $this->state = self::STATE_BOUNDARY_SUFFIX;
                } else {
                    // Safe to discard everything except the last N bytes where a boundary might be forming
                    $keep = \strlen($boundary);
                    if (\strlen($this->buffer) > $keep) {
                        $this->buffer = substr($this->buffer, -$keep);
                    }

                    return;
                }
            } elseif ($this->state === self::STATE_BOUNDARY_SUFFIX) {
                if (\strlen($this->buffer) < 2) {
                    return;
                } // Wait for \r\n or --

                $suffix = substr($this->buffer, 0, 2);
                $this->buffer = substr($this->buffer, 2);

                if ($suffix === '--') {
                    $this->state = self::STATE_EPILOGUE;
                    $this->emit('end');

                    return;
                } elseif ($suffix === "\r\n") {
                    $this->state = self::STATE_HEADERS;
                } else {
                    // Malformed, but attempt recovery
                    $this->state = self::STATE_HEADERS;
                }
            } elseif ($this->state === self::STATE_HEADERS) {
                $pos = strpos($this->buffer, "\r\n\r\n");

                if ($pos !== false) {
                    // Check if the headers that are about to parse exceed the limit
                    if ($pos > $this->maxHeaderSize) {
                        $this->emit('error', [new MultipartPartTooLargeException('Multipart headers too large')]);
                        $this->close();

                        return;
                    }

                    $rawHeaders = substr($this->buffer, 0, $pos);
                    $this->buffer = substr($this->buffer, $pos + 4);
                    $this->state = self::STATE_BODY;
                    $this->processHeaders($rawHeaders);
                } else {
                    // Prevent memory exhaustion from malicious header blocks
                    // that drip in slowly and never send \r\n\r\n
                    if (\strlen($this->buffer) > $this->maxHeaderSize) {
                        $this->emit('error', [new MultipartPartTooLargeException('Multipart headers too large')]);
                        $this->close();
                    }

                    return;
                }
            } elseif ($this->state === self::STATE_BODY) {
                $marker = "\r\n--" . $this->boundary;
                $pos = strpos($this->buffer, $marker);

                if ($pos !== false) {
                    // Complete boundary found!
                    $chunk = substr($this->buffer, 0, $pos);
                    if ($chunk !== '') {
                        $this->emitChunk($chunk);
                    }
                    $this->buffer = substr($this->buffer, $pos + \strlen($marker));
                    $this->state = self::STATE_BOUNDARY_SUFFIX;

                    $this->finishCurrentPart();
                } else {
                    // No complete boundary found. Emit data up to the safe margin.
                    $markerLen = \strlen($marker);
                    $bufferLen = \strlen($this->buffer);

                    if ($bufferLen > $markerLen) {
                        $safeLen = $bufferLen - $markerLen;
                        $chunk = substr($this->buffer, 0, $safeLen);
                        $this->buffer = substr($this->buffer, $safeLen);
                        $this->emitChunk($chunk);
                    }

                    return; // Wait for more TCP packets
                }
            } elseif ($this->state === self::STATE_EPILOGUE) {
                $this->buffer = '';

                return;
            }
        }
    }

    private function processHeaders(string $rawHeaders): void
    {
        // RFC 7578 section 4.2: disposition type MUST be "form-data". Anything else
        // (e.g. "attachment") is not valid multipart/form-data content and
        // MUST NOT be admitted as a field or file.
        $this->currentValid = false;

        if (preg_match('/Content-Disposition:\s*([^;\r\n]+)/i', $rawHeaders, $m) === 1) {
            if (strtolower(trim($m[1])) === 'form-data') {
                $this->currentValid = true;
            }
        }

        if (preg_match('/name="([^"]*)"/i', $rawHeaders, $m) === 1) {
            $this->currentName = $m[1];
        }
        if (preg_match('/filename="([^"]*)"/i', $rawHeaders, $m) === 1) {
            $this->currentFilename = $this->sanitizeFilename($m[1]);
        }
        if (preg_match('/Content-Type:\s*([^\r\n]+)/i', $rawHeaders, $m) === 1) {
            $this->currentMime = trim($m[1]);
        }

        // RFC 7578 section 4.4: "Each part MAY have an (optional) 'Content-Type'
        // header field, which defaults to 'text/plain'." A part with no
        // explicit Content-Type must still be admitted with this default,
        // not dropped by downstream consumers expecting a non-null mime.
        if ($this->currentMime === null) {
            $this->currentMime = 'text/plain';
        }

        if (! $this->currentValid) {
            return;
        }

        if ($this->currentFilename !== null) {
            $this->currentFileStream = new MultipartFileStream();
            $this->emit('file', [$this->currentName, $this->currentFilename, $this->currentMime, $this->currentFileStream]);
        } else {
            $this->currentFieldBuffer = '';
        }
    }

    /**
     * Reduces a client-supplied filename to a bare leaf name, per RFC 7578
     * section 4.2 (citing RFC 2183 section 2.3): "do not use the file name blindly... and
     * do not use directory path information that may be present." Handles
     * both POSIX ("../../etc/evil.txt", "/etc/passwd") and Windows-style
     * ("C:\\evil.txt") separators, since the client's platform is unknown.
     */
    private function sanitizeFilename(string $filename): string
    {
        $normalized = str_replace('\\', '/', $filename);

        return basename($normalized);
    }

    private function emitChunk(string $chunk): void
    {
        if (! $this->currentValid) {
            return;
        }

        if ($this->currentFileStream !== null) {
            $this->currentFileStream->write($chunk);
        } elseif ($this->currentFieldBuffer !== null) {
            $this->currentFieldBuffer .= $chunk;
        }
    }

    private function finishCurrentPart(): void
    {
        if ($this->currentValid) {
            if ($this->currentFileStream !== null) {
                $this->currentFileStream->end();
                $this->currentFileStream = null;
            } elseif ($this->currentFieldBuffer !== null) {
                $this->emit('field', [$this->currentName, $this->currentFieldBuffer]);
                $this->currentFieldBuffer = null;
            }
        }

        $this->currentName = null;
        $this->currentFilename = null;
        $this->currentMime = null;
        $this->currentFieldBuffer = null;
        $this->currentValid = false;
    }
}
