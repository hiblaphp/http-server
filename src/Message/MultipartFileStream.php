<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Message;

use Hibla\EventLoop\Loop;
use Hibla\Stream\ThroughStream;

/**
 * A specialized stream for multipart file uploads that safely buffers incoming
 * data until a consumer (like a Fiber) attaches a 'data' listener.
 *
 * This prevents data loss when asynchronous scheduling delays the attachment
 * of pipe() or data events.
 *
 * @internal
 */
class MultipartFileStream extends ThroughStream
{
    private string $startupBuffer = '';

    private bool $hasDataListener = false;

    private bool $endedEarly = false;

    private bool $flushing = false;

    public function on($event, callable $listener): void
    {
        parent::on($event, $listener);

        if ($event === 'data' && ! $this->hasDataListener) {
            $this->hasDataListener = true;

            if ($this->startupBuffer !== '' || $this->endedEarly) {
                $this->flushing = true;

                Loop::microTask(function () {
                    if ($this->startupBuffer !== '') {
                        $data = $this->startupBuffer;
                        $this->startupBuffer = '';
                        $this->emit('data', [$data]);
                    }
                    $this->flushing = false;
                    if ($this->endedEarly) {
                        parent::end();
                    }
                });
            }
        }
    }

    public function write(string $data): bool
    {
        if (! $this->isWritable()) {
            return false;
        }

        if (! $this->hasDataListener || $this->flushing) {
            $this->startupBuffer .= $data;

            return true;
        }

        return parent::write($data);
    }

    public function end(?string $data = null): void
    {
        if (! $this->isWritable()) {
            return;
        }

        if (! $this->hasDataListener || $this->flushing) {
            if ($data !== null && $data !== '') {
                $this->startupBuffer .= $data;
            }
            $this->endedEarly = true;

            return;
        }

        parent::end($data);
    }

    public function close(): void
    {
        $this->startupBuffer = '';
        $this->hasDataListener = true;
        $this->endedEarly = false;
        $this->flushing = false;

        parent::close();
    }
}
