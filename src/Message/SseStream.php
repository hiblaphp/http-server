<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Message;

use Evenement\EventEmitter;
use Hibla\EventLoop\Loop;
use Hibla\Stream\Interfaces\ReadableStreamInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;
use Hibla\Stream\Util;

class SseStream extends EventEmitter implements ReadableStreamInterface
{
    private bool $readable = true;

    private bool $paused = false;

    /**
     * Tracks whether the Fiber was suspended specifically by TCP backpressure.
     */
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

        // Only resume the Fiber if it was suspended due to backpressure!
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
}