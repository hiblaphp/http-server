<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\HttpServer\Interfaces\ProtocolHandlerInterface;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\Response;
use Hibla\HttpServer\Protocol\Http11ProtocolHandler;

use function Hibla\await;
use function Hibla\delay;

afterEach(function () {
    Loop::reset();
});

describe('Protocol Handler Timeouts', function () {

    it('closes the connection with 408 Request Timeout if headers arrive too slowly', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $handler = new Http11ProtocolHandler(
            $connection,
            function () {
            },
            headerTimeout: 0.1
        );

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\n");

        await(delay(0.15));

        expect($buffer)->toContain('HTTP/1.1 408 Request Timeout')
            ->and($buffer)->toContain('Connection: close')
        ;
    });

    it('cancels the header timeout if the request completes successfully', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) {
                $protocol->writeResponse(new Response(200, [], 'OK'));
            },
            headerTimeout: 0.1
        );

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        await(delay(0.15));

        expect($buffer)->not->toContain('408 Request Timeout')
            ->and($buffer)->toContain('HTTP/1.1 200 OK')
        ;
    });

    it('closes idle connections after the keep-alive timeout expires', function () {
        $buffer = '';
        $wasClosed = false;

        $connection = mockConnection($buffer);
        $connection->on('close', function () use (&$wasClosed) {
            $wasClosed = true;
        });

        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) {
                $protocol->writeResponse(new Response(200, [], 'OK'));
            },
            keepAliveTimeout: 0.1
        );

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
        expect($buffer)->toContain('HTTP/1.1 200 OK');

        expect($wasClosed)->toBeFalse();

        await(delay(0.15));

        expect($wasClosed)->toBeTrue();
    });

    it('closes the connection if client remains silent after initial connection', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $handler = new Http11ProtocolHandler(
            $connection,
            function () {
            },
            headerTimeout: 0.1
        );

        await(delay(0.15));

        expect($buffer)->toContain('HTTP/1.1 408 Request Timeout')
            ->and($buffer)->toContain('Connection: close')
        ;
    });

    it('cancels keep-alive timer and transitions to header timeout when next request first byte arrives', function () {
        $buffer = '';
        $wasClosed = false;

        $connection = mockConnection($buffer);
        $connection->on('close', function () use (&$wasClosed) {
            $wasClosed = true;
        });

        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) {
                $protocol->writeResponse(new Response(200, [], 'OK'));
            },
            headerTimeout: 0.1,
            keepAliveTimeout: 0.1
        );

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
        expect($buffer)->toContain('HTTP/1.1 200 OK');
        $buffer = '';

        await(delay(0.05));
        expect($wasClosed)->toBeFalse();

        $handler->handleData('G');

        await(delay(0.07));
        expect($wasClosed)->toBeFalse();

        await(delay(0.05));

        expect($buffer)->toContain('HTTP/1.1 408 Request Timeout')
            ->and($buffer)->toContain('Connection: close')
        ;
    });

    it('starts the header timeout immediately for pipelined requests waiting in the buffer', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) {
                $protocol->writeResponse(new Response(200, [], 'OK'));
            },
            headerTimeout: 0.1
        );

        $handler->handleData(
            "GET /first HTTP/1.1\r\nHost: localhost\r\n\r\n" .
            "GET /second HTTP/1.1\r\nHost: localhost"
        );

        expect($buffer)->toContain('HTTP/1.1 200 OK');
        $buffer = '';

        await(delay(0.15));

        expect($buffer)->toContain('HTTP/1.1 408 Request Timeout')
            ->and($buffer)->toContain('Connection: close')
        ;
    });
});

describe('Body Timeout (Inactivity)', function () {

    it('closes the connection with 408 if the client sends headers but stalls before sending the body', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $handler = new Http11ProtocolHandler(
            $connection,
            function () {
            },
            bodyTimeout: 0.1
        );

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 100\r\n\r\n");

        await(delay(0.15));

        expect($buffer)->toContain('HTTP/1.1 408 Request Timeout')
            ->and($buffer)->toContain('Connection: close')
        ;
    });

    it('resets the bodyTimeout when data is successfully consumed, allowing slow but active uploads', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) {
                $protocol->writeResponse(new Response(200, [], 'OK'));
            },
            bodyTimeout: 0.2
        );

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 10\r\n\r\n");

        await(delay(0.15));
        $handler->handleData('12345');

        await(delay(0.15));
        $handler->handleData('67890');

        expect($buffer)->toContain('HTTP/1.1 200 OK');
    });

    it('triggers bodyTimeout during chunked encoding if the client stalls between chunks', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $handler = new Http11ProtocolHandler(
            $connection,
            function () {
            },
            bodyTimeout: 0.1
        );

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n");
        $handler->handleData("5\r\nhello\r\n");

        await(delay(0.15));

        expect($buffer)->toContain('HTTP/1.1 408 Request Timeout');
    });

});

describe('Request Timeout (Absolute)', function () {

    it('closes the connection with 408 if the absolute requestTimeout is exceeded despite active trickle data', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $handler = new Http11ProtocolHandler(
            $connection,
            function () {
            },
            bodyTimeout: 0.3,
            requestTimeout: 0.4
        );

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 15\r\n\r\n");

        await(delay(0.2));
        $handler->handleData('12345');

        await(delay(0.2));
        $handler->handleData('67890');

        await(delay(0.1));

        expect($buffer)->toContain('HTTP/1.1 408 Request Timeout');
    });

    it('cancels the absolute requestTimeout when the request finishes, allowing slow responses (like SSE)', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) {
                Loop::addFiber(new Fiber(function () use ($protocol) {
                    await(delay(0.3));
                    $protocol->writeResponse(new Response(200, [], 'Slow Response'));
                }));
            },
            requestTimeout: 0.2
        );

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        await(delay(0.4));

        expect($buffer)->not->toContain('408')
            ->and($buffer)->toContain('HTTP/1.1 200 OK')
            ->and($buffer)->toContain('Slow Response')
        ;
    });

});

describe('Timeout Edge Cases', function () {

    it('safely handles instant pipelined requests without triggering the keep-alive timeout', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) {
                $protocol->writeResponse(new Response(200, [], 'Processed: ' . $request->uri));
            },
            keepAliveTimeout: 0.3
        );

        $payload = "GET /first HTTP/1.1\r\nHost: localhost\r\n\r\n"
            . "GET /second HTTP/1.1\r\nHost: localhost\r\n\r\n";

        $handler->handleData($payload);

        await(delay(0.1));

        expect($buffer)->toContain('Processed: /first')
            ->and($buffer)->toContain('Processed: /second')
        ;

        await(delay(0.25));
    });

    it('does not trigger header timeout during a slow streaming request body upload', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) {
                $body = $request->body;
                $total = 0;
                $body->on('data', function ($chunk) use (&$total) {
                    $total += strlen($chunk);
                });
                $body->on('end', function () use (&$total, $protocol) {
                    $protocol->writeResponse(new Response(200, [], "Uploaded $total bytes"));
                });
            },
            headerTimeout: 0.4
        );

        $handler->handleData("POST /upload HTTP/1.1\r\nHost: localhost\r\nContent-Length: 10\r\n\r\n");

        await(delay(0.2));
        $handler->handleData('12345');

        await(delay(0.2));
        $handler->handleData('67890');

        await(delay(0.05));

        expect($buffer)->toContain('HTTP/1.1 200 OK')
            ->and($buffer)->toContain('Uploaded 10 bytes')
        ;
    });

});
