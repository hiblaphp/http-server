<?php

declare(strict_types=1);

use Hibla\HttpServer\Interfaces\ProtocolHandlerInterface;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\Response;
use Hibla\HttpServer\Protocol\Http11ProtocolHandler;

describe('Protocol-level Graceful Shutdown', function () {
    it('immediately closes the connection on graceful shutdown if idle', function () {
        $buffer = '';
        $connection = createCloseableMockConnection($buffer);
        $wasClosed = false;

        $connection->on('close', function () use (&$wasClosed) {
            $wasClosed = true;
        });

        $handler = new Http11ProtocolHandler($connection, function () {
        });

        $handler->gracefulShutdown();

        expect($wasClosed)->toBeTrue();
    });

    it('does not close the connection immediately if a request is actively processing', function () {
        $buffer = '';
        $connection = createCloseableMockConnection($buffer);
        $wasClosed = false;

        $connection->on('close', function () use (&$wasClosed) {
            $wasClosed = true;
        });

        $handler = new Http11ProtocolHandler($connection, function (Request $request, ProtocolHandlerInterface $protocol) {
            // Simulate slow-running request handler
        });

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $handler->gracefulShutdown();

        expect($wasClosed)->toBeFalse();

        $handler->writeResponse(Response::plaintext('OK'));

        expect($wasClosed)->toBeTrue();
        expect($buffer)->toContain('Connection: close');
    });

    it('discards pipelined requests after a graceful shutdown has been triggered during the first request', function () {
        $buffer = '';
        $connection = createCloseableMockConnection($buffer);

        $requestsProcessed = 0;
        $handler = null;

        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$requestsProcessed, &$handler) {
            $requestsProcessed++;

            if ($requestsProcessed === 1) {
                $handler->gracefulShutdown();
                $handler->writeResponse(Response::plaintext('First OK'));
            }
        });

        $handler->handleData("GET /first HTTP/1.1\r\nHost: localhost\r\n\r\nGET /second HTTP/1.1\r\nHost: localhost\r\n\r\n");

        expect($requestsProcessed)->toBe(1);
        expect($buffer)->toContain('First OK');
    });

    it('is idempotent when gracefulShutdown is called multiple times consecutively', function () {
        $buffer = '';
        $connection = createCloseableMockConnection($buffer);
        $wasClosed = false;

        $connection->on('close', function () use (&$wasClosed) {
            $wasClosed = true;
        });

        $handler = new Http11ProtocolHandler($connection, function () {
        });

        $handler->gracefulShutdown();
        $handler->gracefulShutdown();
        $handler->gracefulShutdown();

        expect($wasClosed)->toBeTrue();
    });

    it('ignores graceful shutdown if the connection has already been detached/upgraded', function () {
        $buffer = '';
        $connection = createCloseableMockConnection($buffer);
        $wasClosed = false;

        $connection->on('close', function () use (&$wasClosed) {
            $wasClosed = true;
        });

        $handler = new Http11ProtocolHandler($connection, function () {
        });

        $handler->detach();

        $handler->gracefulShutdown();

        // The socket must not close because the HTTP protocol handler no longer owns it
        expect($wasClosed)->toBeFalse();
    });
});
