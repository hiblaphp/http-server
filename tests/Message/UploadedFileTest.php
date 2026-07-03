<?php

declare(strict_types=1);

namespace Tests\Message;

use Hibla\EventLoop\Loop;
use Hibla\HttpServer\Message\UploadedFile;
use Hibla\Stream\Exceptions\StreamException;

use function Hibla\await;

afterEach(function () {
    Loop::reset();

    $files = glob(sys_get_temp_dir() . '/hibla_uf_test_*');

    foreach ($files as $file) {
        if (file_exists($file)) {
            @unlink($file);
        }
    }
});


it('exposes the constructor values as readonly properties', function () {
    $tmpPath = tempnam(sys_get_temp_dir(), 'hibla_uf_test_');
    file_put_contents($tmpPath, 'payload');

    $file = new UploadedFile($tmpPath, 'report.pdf', 'application/pdf', 7);

    expect($file->tmpPath)->toBe($tmpPath)
        ->and($file->clientFilename)->toBe('report.pdf')
        ->and($file->clientMediaType)->toBe('application/pdf')
        ->and($file->size)->toBe(7)
    ;

    unlink($tmpPath);
});

it('moves the file to the destination and deletes the temporary source', function () {
    $tmpPath = tempnam(sys_get_temp_dir(), 'hibla_uf_test_');
    file_put_contents($tmpPath, 'move me');

    $destination = sys_get_temp_dir() . '/hibla_uf_test_dest.txt';
    if (file_exists($destination)) {
        unlink($destination);
    }

    $file = new UploadedFile($tmpPath, 'move.txt', 'text/plain', 7);

    await($file->moveTo($destination));

    expect(file_exists($destination))->toBeTrue()
        ->and(file_get_contents($destination))->toBe('move me')
        ->and(file_exists($tmpPath))->toBeFalse()
    ;

    unlink($destination);
});

it('preserves exact byte content for larger multi-chunk transfers', function () {
    $tmpPath = tempnam(sys_get_temp_dir(), 'hibla_uf_test_');
    $content = str_repeat('abcdefghij', 100_000);
    file_put_contents($tmpPath, $content);

    $destination = sys_get_temp_dir() . '/hibla_uf_test_large.bin';
    if (file_exists($destination)) {
        unlink($destination);
    }

    $file = new UploadedFile($tmpPath, 'large.bin', 'application/octet-stream', \strlen($content));

    await($file->moveTo($destination));

    expect(filesize($destination))->toBe(\strlen($content))
        ->and(hash_file('sha256', $destination))->toBe(hash('sha256', $content))
    ;

    unlink($destination);
});

it('rejects a second moveTo() call with a clear exception', function () {
    $tmpPath = tempnam(sys_get_temp_dir(), 'hibla_uf_test_');
    file_put_contents($tmpPath, 'once only');

    $destination1 = sys_get_temp_dir() . '/hibla_uf_test_first.txt';
    $destination2 = sys_get_temp_dir() . '/hibla_uf_test_second.txt';
    foreach ([$destination1, $destination2] as $d) {
        if (file_exists($d)) {
            unlink($d);
        }
    }

    $file = new UploadedFile($tmpPath, 'once.txt', 'text/plain', 9);

    await($file->moveTo($destination1));

    expect(fn() => await($file->moveTo($destination2)))
        ->toThrow(\RuntimeException::class, 'File has already been moved.');

    expect(file_exists($destination2))->toBeFalse();

    unlink($destination1);
});

it('rejects moveTo() if the temporary source no longer exists', function () {
    $tmpPath = sys_get_temp_dir() . '/hibla_uf_test_never_created.txt';
    if (file_exists($tmpPath)) {
        unlink($tmpPath);
    }

    $destination = sys_get_temp_dir() . '/hibla_uf_test_should_not_exist.txt';
    if (file_exists($destination)) {
        unlink($destination);
    }

    $file = new UploadedFile($tmpPath, 'ghost.txt', 'text/plain', 0);

    expect(fn() => await($file->moveTo($destination)))
        ->toThrow(\RuntimeException::class, 'Temporary file no longer exists.');

    expect(file_exists($destination))->toBeFalse();
});

it('cleans up a partial destination file when the destination write errors', function () {
    $tmpPath = tempnam(sys_get_temp_dir(), 'hibla_uf_test_');
    file_put_contents($tmpPath, 'will fail to land');

    $destination = sys_get_temp_dir() . '/hibla_uf_test_missing_dir_' . uniqid() . '/dest.txt';

    $file = new UploadedFile($tmpPath, 'fail.txt', 'text/plain', 18);

    set_error_handler(static fn() => true, E_WARNING);

    try {
        $promise = $file->moveTo($destination);
    } finally {
        restore_error_handler();
    }

    expect(fn() => await($promise))
        ->toThrow(StreamException::class, 'Failed to open file for writing:');

    expect(file_exists($destination))->toBeFalse()
        ->and(file_exists($tmpPath))->toBeTrue()
    ;

    unlink($tmpPath);
});

it('aborts the copy and removes the partial destination on cancellation, leaving the source intact', function () {
    $tmpPath = tempnam(sys_get_temp_dir(), 'hibla_uf_test_');
    file_put_contents($tmpPath, str_repeat('Z', 5 * 1024 * 1024));

    $destination = sys_get_temp_dir() . '/hibla_uf_test_cancelled.bin';
    if (file_exists($destination)) {
        unlink($destination);
    }

    $file = new UploadedFile($tmpPath, 'cancelled.bin', 'application/octet-stream', 5 * 1024 * 1024);

    $promise = $file->moveTo($destination);

    Loop::runOnce();
    $promise->cancel();
    Loop::run();

    expect($promise->isCancelled())->toBeTrue()
        ->and(file_exists($destination))->toBeFalse()
        ->and(file_exists($tmpPath))->toBeTrue()
    ;

    unlink($tmpPath);
});

it('deletes the temporary file on destruction when it was never moved', function () {
    $tmpPath = tempnam(sys_get_temp_dir(), 'hibla_uf_test_');
    file_put_contents($tmpPath, 'orphaned');

    $file = new UploadedFile($tmpPath, 'orphan.txt', 'text/plain', 8);

    expect(file_exists($tmpPath))->toBeTrue();

    unset($file);
    gc_collect_cycles();

    expect(file_exists($tmpPath))->toBeFalse();
});

it('does not attempt to re-delete the temporary file on destruction after a successful move', function () {
    $tmpPath = tempnam(sys_get_temp_dir(), 'hibla_uf_test_');
    file_put_contents($tmpPath, 'already moved');

    $destination = sys_get_temp_dir() . '/hibla_uf_test_destructed.txt';
    if (file_exists($destination)) {
        unlink($destination);
    }

    $file = new UploadedFile($tmpPath, 'moved.txt', 'text/plain', 13);

    await($file->moveTo($destination));

    expect(file_exists($tmpPath))->toBeFalse();

    unset($file);
    gc_collect_cycles();

    expect(file_exists($destination))->toBeTrue();

    unlink($destination);
});
