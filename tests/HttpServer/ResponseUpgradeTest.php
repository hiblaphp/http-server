<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\HttpServer\Message\Request as ServerRequest;
use Hibla\HttpServer\Message\Response as ServerResponse;
use Hibla\Promise\Promise;
use Hibla\Socket\Connector;

use function Hibla\await;

afterEach(function () {
    Loop::reset();
});

describe('Response::upgrade() API Edge Cases', function () {

    it('preserves trailing bytes when the client sends protocol data in the same TCP packet as the HTTP headers', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::upgrade(101, ['Upgrade' => 'custom'], function ($connection, $trailingBytes) {
                $connection->end("TRAILING_WERE: {$trailingBytes}");
            });
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("GET / HTTP/1.1\r\nHost: localhost\r\nUpgrade: custom\r\n\r\n[FIRST_FRAME_DATA]");

            $responsePromise = new Promise(function ($resolve) use ($connection) {
                $buffer = '';
                $connection->on('data', function ($chunk) use (&$buffer) {
                    $buffer .= $chunk;
                });
                $connection->on('close', function () use (&$buffer, $resolve) {
                    $resolve($buffer);
                });
            });

            $rawResponse = await($responsePromise);

            expect($rawResponse)->toContain('HTTP/1.1 101 Switching Protocols')
                ->and($rawResponse)->toContain('TRAILING_WERE: [FIRST_FRAME_DATA]')
            ;
        } finally {
            $socket->close();
        }
    });

    it('processes standard pipelined HTTP requests completely before executing the socket upgrade', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            if ($request->uri === '/api/data') {
                return ServerResponse::plaintext('Standard API JSON');
            }

            if ($request->uri === '/ws') {
                return ServerResponse::upgrade(101, ['Upgrade' => 'websocket'], function ($connection, $trailing) {
                    $connection->end('Socket is now hijacked!');
                });
            }

            return new ServerResponse(404);
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write(
                "GET /api/data HTTP/1.1\r\nHost: localhost\r\n\r\n" .
                "GET /ws HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\n\r\n"
            );

            $responsePromise = new Promise(function ($resolve) use ($connection) {
                $buffer = '';
                $connection->on('data', function ($chunk) use (&$buffer) {
                    $buffer .= $chunk;
                });
                $connection->on('close', function () use (&$buffer, $resolve) {
                    $resolve($buffer);
                });
            });

            $rawResponse = await($responsePromise);

            $apiPos = strpos($rawResponse, 'Standard API JSON');
            $upgradePos = strpos($rawResponse, '101 Switching Protocols');
            $hijackPos = strpos($rawResponse, 'Socket is now hijacked!');

            expect($apiPos)->not->toBeFalse()
                ->and($upgradePos)->not->toBeFalse()
                ->and($hijackPos)->not->toBeFalse()
                ->and($apiPos)->toBeLessThan($upgradePos)
                ->and($upgradePos)->toBeLessThan($hijackPos)
            ;
        } finally {
            $socket->close();
        }
    });

    it('safely closes the socket if the onUpgrade callback throws an exception inside its isolated Fiber', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::upgrade(101, ['Upgrade' => 'custom'], function ($connection, $trailing) {
                throw new RuntimeException('Fatal error establishing WebSocket session!');
            });
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("GET / HTTP/1.1\r\nHost: localhost\r\nUpgrade: custom\r\n\r\n");

            $closedPromise = new Promise(function ($resolve) use ($connection) {
                $connection->on('close', function () use ($resolve) {
                    $resolve(true);
                });
            });

            $wasClosed = await($closedPromise);

            expect($wasClosed)->toBeTrue();

        } finally {
            $socket->close();
        }
    });

    it('can be used to ergonomically build an HTTP CONNECT tunnel', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            if ($request->method === 'CONNECT') {
                return ServerResponse::upgrade(200, [], function ($connection, $trailingBytes) {
                    $connection->write("TUNNEL_ESTABLISHED\n");
                    if ($trailingBytes !== '') {
                        $connection->write('PROXIED: ' . $trailingBytes);
                    }
                    $connection->on('data', fn ($chunk) => $connection->write('PROXIED: ' . $chunk));
                });
            }

            return new ServerResponse(405);
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("CONNECT target.com:443 HTTP/1.1\r\nHost: target.com:443\r\n\r\nEARLY_SSL_HELLO");

            $responsePromise = new Promise(function ($resolve) use ($connection) {
                $buffer = '';
                $connection->on('data', function ($chunk) use (&$buffer, $resolve) {
                    $buffer .= $chunk;
                    if (str_contains($buffer, 'PROXIED: EARLY_SSL_HELLO')) {
                        $resolve($buffer);
                    }
                });
            });

            $rawResponse = await($responsePromise);

            expect($rawResponse)->toContain('HTTP/1.1 200 OK')
                ->and($rawResponse)->toContain('TUNNEL_ESTABLISHED')
                ->and($rawResponse)->toContain('PROXIED: EARLY_SSL_HELLO')
            ;

            $connection->close();
        } finally {
            $socket->close();
        }
    });

});
