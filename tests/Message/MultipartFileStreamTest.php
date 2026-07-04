<?php

declare(strict_types=1);

use Evenement\EventEmitter;
use Hibla\EventLoop\Loop;
use Hibla\HttpServer\Message\MultipartFileStream;
use Hibla\Stream\Interfaces\WritableStreamInterface;

afterEach(function () {
    Loop::reset();
});

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
