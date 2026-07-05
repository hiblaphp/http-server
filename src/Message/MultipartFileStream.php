<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Message;

use Hibla\EventLoop\Loop;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Stream\Handlers\ReadAllHandler;
use Hibla\Stream\Handlers\ReadLineHandler;
use Hibla\Stream\Interfaces\PromiseReadableStreamInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;
use Hibla\Stream\ThroughStream;

/**
 * A specialized stream for multipart file uploads that safely buffers incoming
 * data and implements both event-driven and promise-based APIs.
 *
 * This prevents data loss when asynchronous scheduling delays the attachment
 * of pipe() or data events.
 *
 * @internal
 */
class MultipartFileStream extends ThroughStream implements PromiseReadableStreamInterface
{
    private string $readBuffer = '';

    private bool $hasDataListener = false;

    private bool $endedEarly = false;

    private bool $flushing = false;

    private bool $closed = false;

    private bool $isPaused = false;

    /**
     * @var list<array{resolve: callable(string|null): void, reject: callable(\Throwable): void, length: int, promise: PromiseInterface<string|null>}>
     */
    private array $readQueue = [];

    private ReadLineHandler $lineHandler;

    private ReadAllHandler $allHandler;

    public function __construct()
    {
        parent::__construct();

        $this->lineHandler = new ReadLineHandler(
            fn (?int $length) => $this->readAsync($length),
            fn (string $data) => $this->prependBuffer($data)
        );

        $this->allHandler = new ReadAllHandler(
            65536,
            fn (?int $length) => $this->readAsync($length)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function on($event, callable $listener): void
    {
        parent::on($event, $listener);

        if ($event === 'data' && ! $this->hasDataListener) {
            $this->hasDataListener = true;

            // Defer flushing to the next microtask to prevent out-of-order execution anomalies
            if ($this->readBuffer !== '' || $this->endedEarly) {
                $this->flushing = true;

                Loop::microTask(function () {
                    if ($this->readBuffer !== '') {
                        $data = $this->readBuffer;
                        $this->readBuffer = '';
                        $this->emit('data', [$data]);
                    }

                    $this->flushing = false;

                    // Trigger ThroughStream's natural EOF lifecycle
                    if ($this->endedEarly && ! $this->closed) {
                        parent::end();
                    }
                });
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function write(string $data): bool
    {
        if ($this->closed || $this->endedEarly || ! $this->isWritable()) {
            return false;
        }

        if ($data === '') {
            return true;
        }

        if (! $this->hasDataListener || $this->flushing) {
            $this->readBuffer .= $data;
            $this->resolvePendingReads();

            return ! $this->isPaused;
        }

        return parent::write($data);
    }

    /**
     * {@inheritDoc}
     */
    public function end(?string $data = null): void
    {
        if ($this->closed || $this->endedEarly || ! $this->isWritable()) {
            return;
        }

        if (! $this->hasDataListener || $this->flushing) {
            if ($data !== null && $data !== '') {
                $this->readBuffer .= $data;
            }
            $this->endedEarly = true;
            $this->resolvePendingReads();

            return;
        }

        parent::end($data);
    }

    /**
     * {@inheritDoc}
     */
    public function pause(): void
    {
        $this->isPaused = true;
        parent::pause();
    }

    /**
     * {@inheritDoc}
     */
    public function resume(): void
    {
        $this->isPaused = false;
        parent::resume();
    }

    /**
     * {@inheritDoc}
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->readBuffer = '';
        $this->hasDataListener = true;
        $this->flushing = false;
        $this->isPaused = false;

        // Do NOT reset $this->endedEarly to false here.
        // It serves as a permanent marker that EOF was reached cleanly.

        $this->rejectPendingReads(new \RuntimeException('Stream closed'));

        parent::close();
    }

    private function prependBuffer(string $data): void
    {
        $this->readBuffer = $data . $this->readBuffer;
    }

    private function resolvePendingReads(): void
    {
        while ($this->readQueue !== []) {
            if ($this->readBuffer === '') {
                if ($this->endedEarly) {
                    $item = array_shift($this->readQueue);
                    if (! $item['promise']->isCancelled()) {
                        $item['resolve'](null);
                    }
                    
                    if (! $this->closed && $this->readQueue === []) {
                        parent::end();
                    }
                    
                    continue;
                }
                break;
            }

            $item = array_shift($this->readQueue);
            if ($item['promise']->isCancelled()) {
                continue;
            }

            $length = $item['length'];
            $chunk = substr($this->readBuffer, 0, $length);
            $this->readBuffer = substr($this->readBuffer, $length);

            $item['resolve']($chunk);
        }
    }

    private function rejectPendingReads(\Throwable $e): void
    {
        while ($this->readQueue !== []) {
            $item = array_shift($this->readQueue);
            if (! $item['promise']->isCancelled()) {
                $item['reject']($e);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function readAsync(?int $length = null): PromiseInterface
    {
        if ($this->endedEarly && $this->readBuffer === '') {
            if (! $this->closed) {
                parent::end();
            }

            return Promise::resolved(null);
        }

        if ($this->closed) {
            return Promise::rejected(new \RuntimeException('Stream is closed'));
        }

        $len = $length ?? 65536;

        if ($this->readBuffer !== '') {
            $chunk = substr($this->readBuffer, 0, $len);
            $this->readBuffer = substr($this->readBuffer, $len);

            return Promise::resolved($chunk);
        }

        /** @var Promise<string|null> $promise */
        $promise = new Promise();
        $this->readQueue[] = [
            'resolve' => fn (?string $val) => $promise->resolve($val),
            'reject'  => fn (\Throwable $err) => $promise->reject($err),
            'length'  => $len,
            'promise' => $promise,
        ];

        $promise->onCancel(function () use ($promise) {
            foreach ($this->readQueue as $index => $item) {
                if ($item['promise'] === $promise) {
                    unset($this->readQueue[$index]);
                    $this->readQueue = array_values($this->readQueue);
                    break;
                }
            }
        });

        return $promise;
    }

    /**
     * {@inheritDoc}
     */
    public function readLineAsync(?int $maxLength = null): PromiseInterface
    {
        if ($this->endedEarly && $this->readBuffer === '') {
            if (! $this->closed) {
                parent::end();
            }
            return Promise::resolved(null);
        }

        if ($this->closed) {
            return Promise::rejected(new \RuntimeException('Stream is closed'));
        }

        $maxLen = $maxLength ?? 65536;

        $line = $this->lineHandler->findLineInBuffer($this->readBuffer, $maxLen);
        if ($line !== null) {
            return Promise::resolved($line);
        }

        return $this->lineHandler->readLineFromStream('', $maxLen);
    }

    /**
     * {@inheritDoc}
     */
    public function readAllAsync(int $maxLength = 1048576): PromiseInterface
    {
        if ($this->closed && ! $this->endedEarly) {
            return Promise::rejected(new \RuntimeException('Stream is closed'));
        }

        $buffer = $this->readBuffer;
        $this->readBuffer = '';

        return $this->allHandler->readAll($buffer, $maxLength);
    }

    /**
     * {@inheritDoc}
     */
    public function pipeAsync(WritableStreamInterface $destination, array $options = []): PromiseInterface
    {
        if ($this->closed && ! $this->endedEarly) {
            return Promise::rejected(new \RuntimeException('Stream is closed'));
        }

        if (! $destination->isWritable()) {
            return Promise::rejected(new \RuntimeException('Destination is not writable'));
        }

        $endDestination = (bool) ($options['end'] ?? true);
        $totalBytes = 0;
        $cancelled = false;
        $hasError = false;

        /** @var Promise<int> $promise */
        $promise = new Promise();

        $dataHandler = function (string $data) use ($destination, &$totalBytes, &$cancelled, &$hasError): void {
            if ($cancelled || $hasError) {
                return;
            }

            $feedMore = $destination->write($data);
            $totalBytes += strlen($data);
            if ($feedMore === false) {
                $this->pause();
            }
        };

        $endHandler = function () use ($promise, $destination, $endDestination, &$totalBytes, &$cancelled, &$hasError, &$dataHandler, &$endHandler, &$errorHandler, &$closeHandler): void {
            if ($cancelled || $hasError) {
                return;
            }

            $this->detachPipeHandlers($destination, $dataHandler, $endHandler, $errorHandler, $closeHandler);
            if ($endDestination) {
                $destination->once('finish', function () use ($promise, &$totalBytes): void {
                    $promise->resolve($totalBytes);
                });
                $destination->end();
            } else {
                $promise->resolve($totalBytes);
            }
        };

        $errorHandler = function ($error) use ($promise, $destination, &$cancelled, &$hasError, &$dataHandler, &$endHandler, &$errorHandler, &$closeHandler): void {
            if ($cancelled || $hasError) {
                return;
            }

            $hasError = true;
            $this->detachPipeHandlers($destination, $dataHandler, $endHandler, $errorHandler, $closeHandler);
            $promise->reject($error);
        };

        $closeHandler = function () use ($promise, $destination, &$cancelled, &$hasError, &$dataHandler, &$endHandler, &$errorHandler, &$closeHandler): void {
            if ($cancelled || $hasError) {
                return;
            }

            $this->detachPipeHandlers($destination, $dataHandler, $endHandler, $errorHandler, $closeHandler);
            if ($this->isReadable() && ! $this->endedEarly) {
                $hasError = true;
                $promise->reject(new \RuntimeException('Destination closed before transfer completed'));
            }
        };

        $this->on('data', $dataHandler);
        $this->on('end', $endHandler);
        $this->on('error', $errorHandler);
        $destination->on('close', $closeHandler);

        $drainHandler = function () use (&$cancelled, &$hasError): void {
            if ($cancelled || $hasError) {
                return;
            }

            $this->resume();
        };
        $destination->on('drain', $drainHandler);

        $promise->onCancel(function () use (&$cancelled, $destination, &$dataHandler, &$endHandler, &$errorHandler, &$closeHandler, &$drainHandler): void {
            $cancelled = true;
            $this->pause();
            $this->detachPipeHandlers($destination, $dataHandler, $endHandler, $errorHandler, $closeHandler);
            $destination->removeListener('drain', $drainHandler);
        });

        // Register a dummy 'data' listener to trick the internal microTask into flushing if data exists
        $this->on('data', function () {}); 

        $this->resume();

        return $promise;
    }

    private function detachPipeHandlers(
        WritableStreamInterface $destination,
        ?callable $dataHandler,
        ?callable $endHandler,
        ?callable $errorHandler,
        ?callable $closeHandler
    ): void {
        if ($dataHandler !== null) {
            $this->removeListener('data', $dataHandler);
        }

        if ($endHandler !== null) {
            $this->removeListener('end', $endHandler);
        }

        if ($errorHandler !== null) {
            $this->removeListener('error', $errorHandler);
        }

        if ($closeHandler !== null) {
            $destination->removeListener('close', $closeHandler);
        }
    }
}