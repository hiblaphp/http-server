<?php

declare(strict_types=1);

namespace Tests\Message;

use Evenement\EventEmitter;
use Hibla\EventLoop\Loop;
use Hibla\HttpServer\Internals\MultiPartFileStream;
use Hibla\Promise\Exceptions\CancelledException;
use Hibla\Stream\Interfaces\WritableStreamInterface;

use function Hibla\await;
use function Hibla\delay;

afterEach(function () {
    Loop::reset();
});

describe('Event-driven API', function () {
    it('buffers writes before a data listener is attached and flushes them on next microtask when added', function () {
        $stream = new MultipartFileStream();
        $emittedData = '';
        $ended = false;

        $stream->write('chunk_1_');
        $stream->write('chunk_2');
        $stream->end();

        expect($emittedData)->toBe('');

        $stream->on('data', function (string $data) use (&$emittedData) {
            $emittedData .= $data;
        });
        $stream->on('end', function () use (&$ended) {
            $ended = true;
        });

        Loop::runOnce();

        expect($emittedData)->toBe('chunk_1_chunk_2')
            ->and($ended)->toBeTrue()
        ;
    });

    it('passes data through immediately without any buffering if a listener is already attached', function () {
        $stream = new MultipartFileStream();
        $emittedData = '';
        $ended = false;

        $stream->on('data', function (string $data) use (&$emittedData) {
            $emittedData .= $data;
        });
        $stream->on('end', function () use (&$ended) {
            $ended = true;
        });

        $stream->write('immediate_1_');
        $stream->write('immediate_2');
        $stream->end();

        expect($emittedData)->toBe('immediate_1_immediate_2')
            ->and($ended)->toBeTrue()
        ;
    });

    it('handles late pipe() attachments perfectly without losing a single byte of data', function () {
        $source = new MultipartFileStream();

        $destination = new class () extends EventEmitter implements WritableStreamInterface {
            public string $buffer = '';

            public bool $writable = true;

            public function write(string $data): bool
            {
                $this->buffer .= $data;

                return true;
            }

            public function end(?string $data = null): void
            {
                if ($data !== null) {
                    $this->write($data);
                }
                $this->writable = false;
                $this->emit('finish');
            }

            public function isWritable(): bool
            {
                return $this->writable;
            }

            public function close(): void
            {
                $this->writable = false;
            }
        };

        $source->write('buffered_piped_data');
        $source->end();

        $source->pipe($destination);

        Loop::runOnce();

        expect($destination->buffer)->toBe('buffered_piped_data')
            ->and($destination->isWritable())->toBeFalse()
        ;
    });

    it('guarantees sequential data order when writes occur both before and after attaching the listener', function () {
        $stream = new MultipartFileStream();
        $emittedData = '';

        $stream->write('chunk_1_');

        $stream->on('data', function (string $data) use (&$emittedData) {
            $emittedData .= $data;
        });

        $stream->write('chunk_2');
        $stream->end();

        expect($emittedData)->toBe('');

        Loop::runOnce();

        expect($emittedData)->toBe('chunk_1_chunk_2');
    });

    it('discards all buffered data and ignores subsequent writes if the stream is closed early', function () {
        $stream = new MultipartFileStream();
        $emittedData = '';
        $ended = false;

        $stream->write('discard_me');

        $stream->close();

        $stream->on('data', function (string $data) use (&$emittedData) {
            $emittedData .= $data;
        });
        $stream->on('end', function () use (&$ended) {
            $ended = true;
        });

        $stream->write('ignore_me');

        Loop::runOnce();

        expect($emittedData)->toBe('')
            ->and($ended)->toBeFalse()
        ;
    });

    it('ignores empty string writes without triggering anomalies or unexpected flushes', function () {
        $stream = new MultipartFileStream();
        $emittedData = '';

        $stream->write('');
        $stream->write('valid_data');
        $stream->write('');
        $stream->end();

        $stream->on('data', function (string $data) use (&$emittedData) {
            $emittedData .= $data;
        });

        Loop::runOnce();

        expect($emittedData)->toBe('valid_data');
    });
});

describe('Promise-based API', function () {

    it('resolves readAsync() with buffered data and future data', function () {
        $stream = new MultipartFileStream();

        $stream->write('part1_');

        $chunk1 = await($stream->readAsync(6));

        Loop::addTimer(0.01, function () use ($stream) {
            $stream->write('part2');
            $stream->end();
        });

        $chunk2 = await($stream->readAsync());
        $chunk3 = await($stream->readAsync()); // Should be null on EOF

        expect($chunk1)->toBe('part1_')
            ->and($chunk2)->toBe('part2')
            ->and($chunk3)->toBeNull()
        ;
    });

    it('resolves readLineAsync() with correct line boundaries', function () {
        $stream = new MultipartFileStream();

        Loop::addTimer(0.01, function () use ($stream) {
            $stream->write("Line 1\nLine ");
        });

        Loop::addTimer(0.02, function () use ($stream) {
            $stream->write("2\nLine 3");
            $stream->end();
        });

        $line1 = await($stream->readLineAsync());
        $line2 = await($stream->readLineAsync());
        $line3 = await($stream->readLineAsync());
        $line4 = await($stream->readLineAsync()); // EOF

        expect($line1)->toBe("Line 1\n")
            ->and($line2)->toBe("Line 2\n")
            ->and($line3)->toBe('Line 3')
            ->and($line4)->toBeNull()
        ;
    });

    it('resolves readAllAsync() with all data when the stream ends', function () {
        $stream = new MultipartFileStream();

        $stream->write('Hello');

        Loop::addTimer(0.01, function () use ($stream) {
            $stream->write(' ');
            $stream->write('World');
            $stream->end();
        });

        $fullData = await($stream->readAllAsync());

        expect($fullData)->toBe('Hello World');
    });

    it('pipes data asynchronously via pipeAsync() and resolves with total byte count', function () {
        $source = new MultipartFileStream();

        $destination = new class () extends EventEmitter implements WritableStreamInterface {
            public string $buffer = '';

            public bool $writable = true;

            public function write(string $data): bool
            {
                $this->buffer .= $data;

                return true;
            }

            public function end(?string $data = null): void
            {
                if ($data !== null) {
                    $this->write($data);
                }
                $this->writable = false;
                $this->emit('finish');
            }

            public function isWritable(): bool
            {
                return $this->writable;
            }

            public function close(): void
            {
                $this->writable = false;
                $this->emit('close');
            }
        };

        Loop::addTimer(0.01, function () use ($source) {
            $source->write('test_pipe_');
        });

        Loop::addTimer(0.02, function () use ($source) {
            $source->write('data');
            $source->end();
        });

        $totalBytes = await($source->pipeAsync($destination));

        expect($totalBytes)->toBe(14)
            ->and($destination->buffer)->toBe('test_pipe_data')
            ->and($destination->isWritable())->toBeFalse()
        ;
    });
});

describe('Promise Cancellation', function () {

    it('cancels readAsync() and leaves subsequent bytes safely in the buffer', function () {
        $stream = new MultipartFileStream();

        $promise = $stream->readAsync(1024);

        Loop::addTimer(0.01, function () use ($promise) {
            $promise->cancel();
        });

        Loop::addTimer(0.02, function () use ($stream) {
            $stream->write('chunk_after_cancel');
            $stream->end();
        });

        try {
            await($promise);
            $this->fail('Expected CancelledException to be thrown');
        } catch (CancelledException $e) {
            expect($e)->toBeInstanceOf(CancelledException::class);
        }

        $recoveredData = await($stream->readAllAsync());
        expect($recoveredData)->toBe('chunk_after_cancel');
    });

    it('cancels readAllAsync() mid-stream and preserves future unread bytes', function () {
        $stream = new MultipartFileStream();

        $stream->write('chunk_1_');

        $promise = $stream->readAllAsync();

        Loop::addTimer(0.01, function () use ($promise) {
            $promise->cancel();
        });

        Loop::addTimer(0.02, function () use ($stream) {
            $stream->write('chunk_2');
            $stream->end();
        });

        try {
            await($promise);
            $this->fail('Expected CancelledException');
        } catch (CancelledException $e) {
            expect($e)->toBeInstanceOf(CancelledException::class);
        }

        await(delay(0.03));

        $leftover = await($stream->readAsync());
        expect($leftover)->toBe('chunk_2');
    });

    it('cancels pipeAsync() safely, stops transferring data immediately, and leaves destination open', function () {
        $source = new MultipartFileStream();

        $bytesReceived = 0;
        $destination = new class ($bytesReceived) extends EventEmitter implements WritableStreamInterface {
            public string $buffer = '';

            public bool $writable = true;

            public int $bytes = 0;

            public function __construct(int &$bytes)
            {
                $this->bytes = &$bytes;
            }

            public function write(string $data): bool
            {
                $this->buffer .= $data;
                $this->bytes += \strlen($data);

                return true;
            }

            public function end(?string $data = null): void
            {
                if ($data !== null) {
                    $this->write($data);
                }
                $this->writable = false;
                $this->emit('finish');
            }

            public function isWritable(): bool
            {
                return $this->writable;
            }

            public function close(): void
            {
                $this->writable = false;
                $this->emit('close');
            }
        };

        $promise = $source->pipeAsync($destination);

        $source->write('first_chunk_');

        Loop::addTimer(0.01, function () use ($promise) {
            $promise->cancel();
        });

        Loop::addTimer(0.02, function () use ($source) {
            $source->write('second_chunk_after_cancel');
            $source->end();
        });

        try {
            await($promise);
            $this->fail('Expected CancelledException');
        } catch (CancelledException $e) {
            expect($e)->toBeInstanceOf(CancelledException::class);
        }

        await(delay(0.03));

        expect($destination->bytes)->toBe(12)
            ->and($destination->buffer)->toBe('first_chunk_')
            ->and($destination->buffer)->not->toContain('second_chunk_after_cancel')
            ->and($destination->isWritable())->toBeTrue()
        ;
    });

    it('rejects pending reads if the stream is closed abruptly', function () {
        $stream = new MultipartFileStream();

        $promise = $stream->readAsync();

        Loop::addTimer(0.01, function () use ($stream) {
            $stream->close();
        });

        try {
            await($promise);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            expect($e->getMessage())->toBe('Stream closed');
        }
    });
});
