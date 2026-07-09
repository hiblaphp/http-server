<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\HttpServer\HttpServer;
use Hibla\HttpServer\Message\Request as ServerRequest;
use Hibla\HttpServer\Message\Response as ServerResponse;
use Hibla\Promise\Promise;
use Hibla\Socket\Connector;

use function Hibla\await;
use function Hibla\delay;

afterEach(function () {
    Loop::reset();

    unset($GLOBALS['resolveDisconnect']);
    unset($GLOBALS['resolveGlobal']);
});

describe('Functional: Client Disconnect Detection', function () {

    it('triggers the per-request onClientDisconnect callback when a client drops the connection early', function () {
        $disconnectFired = new Promise(fn ($resolve) => $GLOBALS['resolveDisconnect'] = $resolve);

        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $request->onClientDisconnect(function () {
                $GLOBALS['resolveDisconnect'](true);
            });

            await(delay(0.2));

            return ServerResponse::plaintext('Done');
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

            await(delay(0.05));
            $connection->close();

            $result = await($disconnectFired);
            expect($result)->toBeTrue();
        } finally {
            $socket->close();
        }
    });

    it('triggers the global onClientDisconnect callback when a client drops the connection early', function () {
        $globalDisconnectFired = new Promise(fn ($resolve) => $GLOBALS['resolveGlobal'] = $resolve);

        [$socket, $url] = createTestServer(
            requestHandler: function (ServerRequest $request) {
                await(delay(0.2));

                return ServerResponse::plaintext('Done');
            },
            onClientDisconnect: function (ServerRequest $request) {
                $GLOBALS['resolveGlobal']($request->uri);
            }
        );

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write("GET /aborted-endpoint HTTP/1.1\r\nHost: localhost\r\n\r\n");

            await(delay(0.05));
            $connection->close();

            $abortedUri = await($globalDisconnectFired);

            expect($abortedUri)->toBe('/aborted-endpoint');
        } finally {
            $socket->close();
        }
    });

    it('does NOT trigger disconnect callbacks if the request completes successfully', function () {
        $disconnectTriggered = false;

        [$socket, $url] = createTestServer(
            requestHandler: function (ServerRequest $request) use (&$disconnectTriggered) {
                $request->onClientDisconnect(function () use (&$disconnectTriggered) {
                    $disconnectTriggered = true;
                });

                return ServerResponse::plaintext('Completed Normally');
            },
            onClientDisconnect: function () use (&$disconnectTriggered) {
                $disconnectTriggered = true;
            }
        );

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $responsePromise = new Promise(function ($resolve) use ($connection) {
                $buffer = '';
                $connection->on('data', function ($chunk) use (&$buffer, $resolve, $connection) {
                    $buffer .= $chunk;
                    if (str_contains($buffer, 'Completed Normally')) {
                        $resolve($buffer);
                        $connection->close();
                    }
                });
            });

            $connection->write("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
            await($responsePromise);

            await(delay(0.05));

            expect($disconnectTriggered)->toBeFalse();
        } finally {
            $socket->close();
        }
    });

    it('triggers callbacks for ALL queued requests if the connection drops during HTTP Pipelining', function () {
        $abortedUris = [];

        [$socket, $url] = createTestServer(
            requestHandler: function (ServerRequest $request) {
                await(delay(0.3));

                return ServerResponse::plaintext('Done');
            },
            onClientDisconnect: function (ServerRequest $request) use (&$abortedUris) {
                $abortedUris[] = $request->uri;
            }
        );

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $connection->write(
                "GET /pipe-1 HTTP/1.1\r\nHost: localhost\r\n\r\n" .
                "GET /pipe-2 HTTP/1.1\r\nHost: localhost\r\n\r\n" .
                "GET /pipe-3 HTTP/1.1\r\nHost: localhost\r\n\r\n"
            );

            await(delay(0.05));
            $connection->close();

            await(delay(0.05));

            expect($abortedUris)->toHaveCount(3)
                ->and($abortedUris)->toContain('/pipe-1')
                ->and($abortedUris)->toContain('/pipe-2')
                ->and($abortedUris)->toContain('/pipe-3')
            ;

        } finally {
            $socket->close();
        }
    });
});

describe('Integration: Client Disconnect Detection', function () {

    it('executes global onClientDisconnect in a real isolated background process', function () {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Process forking is not supported on Windows.');
        }

        $port = random_int(10000, 15000);
        $address = "127.0.0.1:{$port}";

        $lockFile = sys_get_temp_dir() . '/hibla_disconnect_test.lock';
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }

        $pid = pcntl_fork();
        expect($pid)->not->toBe(-1);

        if ($pid === 0) {
            try {
                HttpServer::create($address)
                    ->withoutLogging()
                    ->onClientDisconnect(function (ServerRequest $request) use ($lockFile) {
                        file_put_contents($lockFile, 'DISCONNECTED: ' . $request->uri);
                    })
                    ->onRequest(function (ServerRequest $request) {
                        await(delay(1.0));

                        return ServerResponse::plaintext('OK');
                    })
                    ->start()
                ;
                exit(0);
            } catch (Throwable $e) {
                exit(1);
            }
        }

        try {
            usleep(150000);

            $fp = stream_socket_client("tcp://{$address}", $errno, $errstr, 1.0);
            expect($fp)->not->toBeFalse();

            fwrite($fp, "GET /real-world-drop HTTP/1.1\r\nHost: localhost\r\n\r\n");

            usleep(50000);

            fclose($fp);

            $found = false;
            for ($i = 0; $i < 10; $i++) {
                if (file_exists($lockFile)) {
                    $found = true;

                    break;
                }
                usleep(50000);
            }

            expect($found)->toBeTrue();
            expect(file_get_contents($lockFile))->toBe('DISCONNECTED: /real-world-drop');

        } finally {
            posix_kill($pid, SIGTERM);
            pcntl_waitpid($pid, $status);

            if (file_exists($lockFile)) {
                unlink($lockFile);
            }
        }
    });
});
