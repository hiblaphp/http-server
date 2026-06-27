<?php

declare(strict_types=1);

use Hibla\HttpServer\Protocol\Http11ProtocolHandler;

describe('Critical 1 — Chunk size pre-validation against maxBodySize', function () {

    it('rejects immediately when declared chunk-size exceeds maxBodySize before any chunk data arrives', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler(
            $connection,
            function () use (&$requestReached) {
                $requestReached = true;
            },
            maxBodySize: 16
        );

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . "ff\r\n"; // declares 255 bytes; maxBodySize is 16 so no data follows

        $handler->handleData($raw);

        expect($buffer)->toContain('HTTP/1.1 413 Payload Too Large')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('rejects when a large chunk-size arrives split across TCP packets, with no data ever sent', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler(
            $connection,
            function () use (&$requestReached) {
                $requestReached = true;
            },
            maxBodySize: 16
        );

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n");
        expect($buffer)->not->toContain('413');

        $handler->handleData("ff\r\n");

        expect($buffer)->toContain('HTTP/1.1 413 Payload Too Large')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('does not accumulate chunk data in the buffer before enforcing the size limit', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler(
            $connection,
            function () use (&$requestReached) {
                $requestReached = true;
            },
            maxBodySize: 16
        );

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\nff\r\n");

        $bufferAfterSizeLine = $buffer;

        $handler->handleData(str_repeat('A', 32));

        expect($buffer)->toBe($bufferAfterSizeLine)
            ->and($buffer)->toContain('HTTP/1.1 413 Payload Too Large')
            ->and($requestReached)->toBeFalse()
        ;
    });

});

describe('Critical 2 — hexdec() float overflow on long chunk-size hex strings', function () {

    it('rejects a chunk-size of exactly 16 hex digits with 400 Bad Request', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . "8000000000000000\r\n"; // exactly 16 hex digits

        $handler->handleData($raw);

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('rejects ffffffffffffffff (max uint64) which overflows to -1 and bypasses size checks', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . "ffffffffffffffff\r\nhello\r\n0\r\n\r\n";

        $handler->handleData($raw);

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('rejects a chunk-size hex string longer than 16 digits with 400 Bad Request', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . "1ffffffffffffffff\r\n"; // 17 hex digits

        $handler->handleData($raw);

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('accepts a valid chunk-size with 15 hex digits and handles it without arithmetic corruption', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $handler = new Http11ProtocolHandler(
            $connection,
            function () {
            },
            maxBodySize: 16
        );

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . "fffffffffffffff\r\n"; // 15 hex digits, valid format, enormous size

        $handler->handleData($raw);

        expect($buffer)->toContain('HTTP/1.1 413 Payload Too Large')
            ->and($buffer)->not->toContain('400');
    });

});
