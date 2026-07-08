<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Message;

use Evenement\EventEmitter;
use Hibla\EventLoop\Loop;
use Hibla\Stream\Interfaces\ReadableStreamInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;
use Hibla\Stream\Util;

/**
 * A specialized asynchronous stream implementation of Server-Sent Events (SSE).
 * 
 * This stream implements the WHATWG HTML Living Standard (Section 9.2) wire format
 * and communication protocol to stream real-time events over a persistent HTTP channel
 * to EventSource browser clients.
 * 
 * ### Standard Compliance Highlights:
 * - **Section 9.2.5 (Framing & Delimiters)**: Separates fields with a compliant single LF (\n) 
 *   delimiter and dispatches events correctly by appending a trailing empty line (\n\n) which 
 *   triggers immediate browser-side event emission.
 * - **Section 9.2.6 (Interpreting & Wire Format)**: Enforces precise colon-space delimiter
 *   formatting (e.g., `data: `, `event: `, `id: `, `retry: `) which is parsed literally and 
 *   accurately by user-agent parsers.
 * - **Section 9.2.7 (Keep-Alive Comments)**: Exposes a `ping()` method to stream unparsed 
 *   comment lines (prefixed with a `:` colon) every few seconds to mitigate premature 
 *   connection termination by proxy servers, reverse-proxies, or cloud load balancers.
 * 
 * ### Concurrency & Flow Control:
 * To prevent high CPU utilization and memory exhaustion under heavy loads, this class coordinates
 * background execution using a dedicated background Fiber. It tracks its own backpressure states 
 * using internal flags, pausing and resuming background loop cycles only when TCP buffer saturation 
 * warrants, leaving application-level asynchronous delays (like database calls or sleep timers) 
 * undisturbed.
 * 
 * @see https://html.spec.whatwg.org/multipage/server-sent-events.html
 */
class SseStream extends EventEmitter implements ReadableStreamInterface
{
    private bool $readable = true;

    private bool $paused = false;

    private bool $suspendedByBackpressure = false;

    /**
     * @var \Fiber<mixed, mixed, mixed, mixed>|null The working fiber driving this stream
     */
    private ?\Fiber $emitterFiber = null;

    /**
     * @param (callable(self): void)|null $emitter Optional emitter callback that drives this SSE stream
     */
    public function __construct(?callable $emitter = null)
    {
        if ($emitter !== null) {
            $this->emitterFiber = new \Fiber(function () use ($emitter) {
                try {
                    $emitter($this);
                } catch (\Throwable) {
                    // Connection dropping unwinds the fiber safely
                } finally {
                    $this->end();
                }
            });

            Loop::addFiber($this->emitterFiber);
        }
    }

    public function isReadable(): bool
    {
        return $this->readable;
    }

    public function pause(): void
    {
        $this->paused = true;
    }

    public function resume(): void
    {
        $this->paused = false;

        if ($this->emitterFiber !== null && $this->suspendedByBackpressure) {
            Loop::scheduleFiber($this->emitterFiber);
        }
    }

    public function pipe(WritableStreamInterface $dest, array $options = []): WritableStreamInterface
    {
        Util::pipe($this, $dest, $options);

        return $dest;
    }

    public function close(): void
    {
        if (! $this->readable) {
            return;
        }
        $this->readable = false;
        $this->emit('close');
        $this->removeAllListeners();

        if ($this->emitterFiber !== null && $this->suspendedByBackpressure) {
            Loop::scheduleFiber($this->emitterFiber);
        }
    }

    public function end(): void
    {
        if (! $this->readable) {
            return;
        }
        $this->emit('end');
        $this->close();
    }

    /**
     * Safely formats and pushes an SSE message to the client.
     * Applies backpressure by suspending the fiber if the stream is paused.
     */
    public function send(string $data, ?string $event = null, ?string $id = null, ?int $retry = null): void
    {
        if ($this->paused && $this->emitterFiber !== null && \Fiber::getCurrent() === $this->emitterFiber) {
            $this->suspendedByBackpressure = true;
            \Fiber::suspend();
            $this->suspendedByBackpressure = false;
        }

        if (! $this->readable) {
            return;
        }

        $payload = '';
        if ($id !== null) {
            $payload .= "id: {$id}\n";
        }
        if ($event !== null) {
            $payload .= "event: {$event}\n";
        }
        if ($retry !== null) {
            $payload .= "retry: {$retry}\n";
        }

        $lines = explode("\n", $data);
        foreach ($lines as $line) {
            $payload .= "data: {$line}\n";
        }
        $payload .= "\n";

        $this->emit('data', [$payload]);
    }

    /**
     * Emits a standard-compliant SSE comment block (a line prefixed with a colon).
     * Used primarily for keeping connections alive through proxy timeouts.
     * 
     * @see Section 9.2.7 - Connection Keep-Alive Comments
     */
    public function ping(?string $comment = 'ping'): void
    {
        if ($this->paused && $this->emitterFiber !== null && \Fiber::getCurrent() === $this->emitterFiber) {
            $this->suspendedByBackpressure = true;
            \Fiber::suspend();
            $this->suspendedByBackpressure = false;
        }

        if (! $this->readable) {
            return;
        }

        $commentLine = $comment !== null ? ": " . str_replace("\n", ' ', $comment) : ":";
        $this->emit('data', ["{$commentLine}\n\n"]);
    }
}
