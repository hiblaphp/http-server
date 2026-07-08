<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Http;
use Hibla\HttpClient\SSE\SSEControl;
use Hibla\HttpClient\SSE\SSEEvent as ClientSseEvent;
use Hibla\HttpServer\Exceptions\MultipartPartTooLargeException;
use Hibla\HttpServer\Internals\MultipartParser;
use Hibla\HttpServer\Message\Request as ServerRequest;
use Hibla\HttpServer\Message\Response as ServerResponse;
use Hibla\HttpServer\Message\SseStream as ServerSseStream;
use Hibla\Promise\Promise;
use Hibla\Socket\Connector;
use Hibla\Stream\Interfaces\PromiseReadableStreamInterface;
use Hibla\Stream\Stream;
use Hibla\Stream\ThroughStream;

use function Hibla\await;
use function Hibla\delay;

afterEach(function () {
    Loop::reset();
});

describe('Core HTTP Functionality', function () {
    it('handles a real GET request end-to-end', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext("Hello from the Server! Method: {$request->getMethod()}");
        });

        try {
            $response = await(Http::get($url));
            expect($response->status())->toBe(200)
                ->and($response->body())->toBe('Hello from the Server! Method: GET')
            ;
        } finally {
            $socket->close();
        }
    });

    it('handles a real POST request with a JSON payload', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $data = await($request->getJson());

            return ServerResponse::json(['received_name' => $data['name']]);
        });

        try {
            $response = await(Http::post($url, ['name' => 'Hibla']));
            expect($response->status())->toBe(200)
                ->and($response->json('received_name'))->toBe('Hibla')
            ;
        } finally {
            $socket->close();
        }
    });

    it('handles streaming responses using chunked transfer encoding', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $stream = new ThroughStream();
            Loop::addTimer(0.01, function () use ($stream) {
                $stream->write("Chunk 1\n");
                $stream->write("Chunk 2\n");
                $stream->end();
            });

            return new ServerResponse(200, [], $stream);
        });

        try {
            $receivedData = '';
            $response = await(Http::stream($url, function (string $chunk) use (&$receivedData) {
                $receivedData .= $chunk;
            }));
            await($response->readAllAsync());

            expect($response->status())->toBe(200)
                ->and($response->header('transfer-encoding'))->toBe('chunked')
                ->and($receivedData)->toBe("Chunk 1\nChunk 2\n")
            ;
        } finally {
            $socket->close();
        }
    });

    it('transmits Server-Sent Events from server to client seamlessly', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::sse(function (ServerSseStream $stream) {
                $stream->send(data: 'event 1', id: '101');
                $stream->send(data: 'event 2', event: 'custom_event');
            });
        });

        try {
            $receivedEvents = [];
            $resolveEvents = null;
            $eventsCollected = new Promise(function ($resolve) use (&$resolveEvents) {
                $resolveEvents = $resolve;
            });

            $promise = Http::sse($url)
                ->withoutReconnection()
                ->onEvent(function (ClientSseEvent $event, SSEControl $control) use (&$receivedEvents, &$resolveEvents) {
                    $receivedEvents[] = $event;
                    if (count($receivedEvents) === 2 && $resolveEvents) {
                        $resolveEvents(true);
                    }
                })
                ->connect()
            ;

            $connection = await($promise);
            await($eventsCollected);
            $connection->close();

            expect($receivedEvents)->toHaveCount(2)
                ->and($receivedEvents[0]->data)->toBe('event 1')
                ->and($receivedEvents[0]->id)->toBe('101')
                ->and($receivedEvents[1]->data)->toBe('event 2')
                ->and($receivedEvents[1]->event)->toBe('custom_event')
            ;
        } finally {
            $socket->close();
        }
    });

    it('handles massive concurrency without dropping connections', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $id = $request->getHeaderLine('X-Request-ID');

            return ServerResponse::plaintext("OK: {$id}");
        });

        try {
            $promises = [];
            for ($i = 0; $i < 100; $i++) {
                $promises[] = Http::client()->withHeader('X-Request-ID', (string) $i)->get($url);
            }

            $responses = await(Promise::all($promises));
            expect($responses)->toHaveCount(100);

            foreach ($responses as $index => $response) {
                expect($response->status())->toBe(200)
                    ->and($response->body())->toBe("OK: {$index}")
                ;
            }
        } finally {
            $socket->close();
        }
    });
});

describe('Browser-Like Simulation', function () {
    it('persists cookies across requests like a real browser', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            if ($request->getUri() === '/login') {
                return new ServerResponse(200, ['Set-Cookie' => 'session_id=xyz123; HttpOnly'], 'Logged in');
            }
            if ($request->getUri() === '/dashboard') {
                $cookie = $request->getHeaderLine('Cookie');

                return ServerResponse::plaintext("Cookie received: {$cookie}");
            }

            return new ServerResponse(404);
        });

        try {
            $client = Http::client()->withCookieJar();

            $loginResponse = await($client->get($url . '/login'));
            expect($loginResponse->status())->toBe(200);

            $dashboardResponse = await($client->get($url . '/dashboard'));
            expect($dashboardResponse->body())->toBe('Cookie received: session_id=xyz123');
        } finally {
            $socket->close();
        }
    });

    it('follows redirects automatically', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            if ($request->getUri() === '/old-page') {
                return new ServerResponse(302, ['Location' => '/new-page']);
            }
            if ($request->getUri() === '/new-page') {
                return ServerResponse::plaintext('You have reached the new page');
            }

            return new ServerResponse(404);
        });

        try {
            $response = await(Http::client()->redirects(follow: true, max: 5)->get($url . '/old-page'));
            expect($response->status())->toBe(200)
                ->and($response->body())->toBe('You have reached the new page')
            ;
        } finally {
            $socket->close();
        }
    });

    it('submits multipart form data including file uploads', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $contentType = $request->getHeaderLine('Content-Type');
            $body = await($request->getBufferedBody());

            if (str_contains($contentType, 'multipart/form-data') && str_contains($body, 'dummy_file_content')) {
                return ServerResponse::plaintext('Upload successful');
            }

            return new ServerResponse(400);
        });

        $tmpFile = tempnam(sys_get_temp_dir(), 'hibla_test_');
        file_put_contents($tmpFile, 'dummy_file_content');

        try {
            $response = await(Http::client()
                ->multipartWithFiles(data: ['username' => 'test_user'], files: ['avatar' => $tmpFile])
                ->post($url . '/upload'));

            expect($response->status())->toBe(200)
                ->and($response->body())->toBe('Upload successful')
            ;
        } finally {
            @unlink($tmpFile);
            $socket->close();
        }
    });

    it('fetches concurrent assets like a browser rendering a page', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $uri = $request->getUri();
            if ($uri === '/index.html') {
                return ServerResponse::html('<link href="/style.css"><script src="/app.js"></script><img src="/logo.png">');
            }
            if ($uri === '/style.css') {
                return new ServerResponse(200, ['Content-Type' => 'text/css'], 'body { color: red; }');
            }
            if ($uri === '/app.js') {
                return new ServerResponse(200, ['Content-Type' => 'application/javascript'], 'console.log("hi");');
            }
            if ($uri === '/logo.png') {
                return new ServerResponse(200, ['Content-Type' => 'image/png'], 'fake_png_bytes');
            }

            return new ServerResponse(404);
        });

        try {
            $htmlResponse = await(Http::get($url . '/index.html'));
            expect($htmlResponse->status())->toBe(200);

            $assets = ['/style.css', '/app.js', '/logo.png'];
            $promises = array_map(fn ($asset) => Http::get($url . $asset), $assets);

            $responses = await(Promise::all($promises));

            expect($responses)->toHaveCount(3)
                ->and($responses[0]->header('content-type'))->toBe('text/css')
                ->and($responses[1]->header('content-type'))->toBe('application/javascript')
                ->and($responses[2]->header('content-type'))->toBe('image/png')
            ;
        } finally {
            $socket->close();
        }
    });

    it('supports true duplex streaming by piping an incoming request stream directly to an outgoing response stream', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $reqBody = $request->getBody();
            $resBody = new ThroughStream();
            $reqBody->pipe($resBody);

            return new ServerResponse(200, [], $resBody);
        }, maxBodySize: 10485760);

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $echoPromise = new Promise(function ($resolve) use ($connection) {
                $buffer = '';
                $connection->on('data', function ($chunk) use (&$buffer, $resolve, $connection) {
                    $buffer .= $chunk;
                    if (str_contains($buffer, 'hello')) {
                        $resolve($buffer);
                        $connection->close();
                    }
                });
            });

            $connection->write("POST /duplex HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n");
            await(delay(0.01));

            $connection->write("5\r\nhello\r\n");
            $connection->write("0\r\n\r\n");

            $rawResponse = await($echoPromise);

            expect($rawResponse)->toContain('HTTP/1.1 200 OK')
                ->and($rawResponse)->toContain('Transfer-Encoding: chunked')
                ->and($rawResponse)->toContain("5\r\nhello\r\n")
            ;
        } finally {
            $socket->close();
        }
    });
});

describe('Advanced Client-Server Interactions', function () {
    it('streams massive request bodies asynchronously without buffering in memory', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $stream = $request->getBody();
            $receivedBytes = 0;

            $uploadPromise = new Promise(function ($resolve) use ($stream, &$receivedBytes) {
                $stream->on('data', function (string $chunk) use (&$receivedBytes) {
                    $receivedBytes += strlen($chunk);
                });

                $stream->on('end', function () use (&$receivedBytes, $resolve) {
                    $resolve($receivedBytes);
                });
            });

            $totalBytes = await($uploadPromise);

            return ServerResponse::plaintext("Fully streamed {$totalBytes} bytes");
        }, maxBodySize: 10485760);

        try {
            $payload = str_repeat('X', 1024 * 1024);
            $response = await(Http::post($url, ['data' => $payload]));

            expect($response->status())->toBe(200)
                ->and($response->body())->toContain('Fully streamed')
            ;
        } finally {
            $socket->close();
        }
    });

    it('accurately preserves and routes complex query parameters', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext("URI: {$request->getUri()}");
        });

        try {
            $response = await(Http::get($url . '/api/search', [
                'q' => 'hibla async',
                'filters' => ['active' => 'true', 'id' => 42],
            ]));

            expect($response->status())->toBe(200)
                ->and($response->body())->toBe('URI: /api/search?q=hibla+async&filters%5Bactive%5D=true&filters%5Bid%5D=42')
            ;
        } finally {
            $socket->close();
        }
    });

    it('handles custom request and response headers seamlessly', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $clientAuth = $request->getHeaderLine('X-Client-Auth');

            if ($clientAuth !== 'super-secret') {
                return new ServerResponse(401, [], 'Unauthorized');
            }

            return new ServerResponse(200, [
                'X-Server-Ack' => 'authenticated',
                'X-RateLimit-Remaining' => '99',
            ], 'Welcome');
        });

        try {
            $client = Http::client()->withHeader('X-Client-Auth', 'super-secret');
            $response = await($client->get($url));

            expect($response->status())->toBe(200)
                ->and($response->body())->toBe('Welcome')
                ->and($response->header('x-server-ack'))->toBe('authenticated')
                ->and($response->header('x-ratelimit-remaining'))->toBe('99')
            ;

            $badResponse = await(Http::client()->get($url));
            expect($badResponse->status())->toBe(401);
        } finally {
            $socket->close();
        }
    });

    it('correctly reports HTTP 404 Not Found and custom status codes', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            if ($request->getUri() === '/teapot') {
                return new ServerResponse(418, [], 'Short and stout');
            }

            return new ServerResponse(404, [], 'Page not found');
        });

        try {
            $notFound = await(Http::get($url . '/missing'));
            expect($notFound->status())->toBe(404)
                ->and($notFound->body())->toBe('Page not found')
            ;

            $teapot = await(Http::get($url . '/teapot'));
            expect($teapot->status())->toBe(418)
                ->and($teapot->body())->toBe('Short and stout')
            ;
        } finally {
            $socket->close();
        }
    });

    it('performs strict content negotiation based on the Accept request header', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $accept = $request->getHeaderLine('Accept');

            if (str_contains($accept, 'application/json')) {
                return ServerResponse::json(['format' => 'json']);
            }
            if (str_contains($accept, 'application/xml')) {
                return new ServerResponse(200, ['Content-Type' => 'application/xml'], '<format>xml</format>');
            }

            return ServerResponse::plaintext('format: plain');
        });

        try {
            $jsonRes = await(Http::client()->accept('application/json')->get($url));
            expect($jsonRes->status())->toBe(200)
                ->and($jsonRes->header('content-type'))->toBe('application/json')
                ->and($jsonRes->json('format'))->toBe('json')
            ;

            $xmlRes = await(Http::client()->accept('application/xml')->get($url));
            expect($xmlRes->status())->toBe(200)
                ->and($xmlRes->header('content-type'))->toBe('application/xml')
                ->and($xmlRes->body())->toBe('<format>xml</format>')
            ;

            $plainRes = await(Http::client()->get($url));
            expect($plainRes->status())->toBe(200)
                ->and($plainRes->body())->toBe('format: plain')
            ;
        } finally {
            $socket->close();
        }
    });

    it('safely handles slow clients that dribble request bodies over time', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $body = await($request->getBufferedBody());

            return ServerResponse::plaintext('Received: ' . $body);
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $responsePromise = new Promise(function ($resolve) use ($connection) {
                $buffer = '';
                $connection->on('data', function ($chunk) use (&$buffer, $resolve, $connection) {
                    $buffer .= $chunk;
                    if (str_contains($buffer, 'Received: Slow Client')) {
                        $resolve($buffer);
                        $connection->close();
                    }
                });
            });

            $connection->write("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 11\r\n\r\n");
            Loop::addTimer(0.01, fn () => $connection->write('Slo'));
            Loop::addTimer(0.02, fn () => $connection->write('w '));
            Loop::addTimer(0.03, fn () => $connection->write('Client'));

            $rawResponse = await($responsePromise);

            expect($rawResponse)->toContain('HTTP/1.1 200 OK')
                ->and($rawResponse)->toContain('Received: Slow Client')
            ;
        } finally {
            $socket->close();
        }
    });

    it('modifies streaming request data on-the-fly using a ThroughStream transformer', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $reqBody = $request->getBody();
            $uppercaseStream = new ThroughStream(function (string $chunk) {
                return strtoupper($chunk);
            });
            $reqBody->pipe($uppercaseStream);

            return new ServerResponse(200, [], $uppercaseStream);
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $responsePromise = new Promise(function ($resolve) use ($connection) {
                $buffer = '';
                $connection->on('data', function ($chunk) use (&$buffer, $resolve, $connection) {
                    $buffer .= $chunk;
                    if (str_contains($buffer, "0\r\n\r\n")) {
                        $resolve($buffer);
                        $connection->close();
                    }
                });
            });

            $connection->write("POST /transform HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n");
            $connection->write("5\r\nhello\r\n");
            $connection->write("6\r\n world\r\n");
            $connection->write("0\r\n\r\n");

            $rawResponse = await($responsePromise);

            expect($rawResponse)->toContain('HTTP/1.1 200 OK')
                ->and($rawResponse)->toContain('HELLO')
                ->and($rawResponse)->toContain(' WORLD')
            ;
        } finally {
            $socket->close();
        }
    });

    it('serves static files asynchronously from disk without loading them into memory', function () {
        $tmpFile = tempnam(sys_get_temp_dir(), 'hibla_static_');
        $fileContent = str_repeat("Hello Hibla!\n", 75000);
        file_put_contents($tmpFile, $fileContent);

        [$socket, $url] = createTestServer(function (ServerRequest $request) use ($tmpFile) {
            $fileStream = Stream::readableFile($tmpFile);

            return new ServerResponse(200, [
                'Content-Type' => 'text/plain',
                'Content-Length' => (string) filesize($tmpFile),
            ], $fileStream);
        });

        try {
            $response = await(Http::get($url));

            expect($response->status())->toBe(200)
                ->and(strlen($response->body()))->toBe(strlen($fileContent))
                ->and($response->body())->toBe($fileContent)
            ;
        } finally {
            @unlink($tmpFile);
            $socket->close();
        }
    });

    it('fully parses multipart form data into fields and temporary files via getParsedBody', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            try {
                $form = await($request->getParsedBody());
                $username = $form->get('username');
                $file = $form->getFile('avatar');
                $fileContent = $file ? file_get_contents($file->tmpPath) : null;

                return ServerResponse::json([
                    'parsed_username' => $username,
                    'parsed_filename' => $file ? $file->clientFilename : null,
                    'file_content' => $fileContent,
                ]);
            } catch (Throwable $e) {
                return new ServerResponse(500, [], $e->getMessage());
            }
        });

        $tmpFile = tempnam(sys_get_temp_dir(), 'hibla_test_');
        file_put_contents($tmpFile, 'actual_binary_data_123');

        try {
            $response = await(Http::client()
                ->multipartWithFiles(
                    data: ['username' => 'alice_smith'],
                    files: ['avatar' => $tmpFile]
                )
                ->post($url . '/upload'));

            expect($response->status())->toBe(200)
                ->and($response->json('parsed_username'))->toBe('alice_smith')
                ->and($response->json('parsed_filename'))->toBe(basename($tmpFile))
                ->and($response->json('file_content'))->toBe('actual_binary_data_123')
            ;
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
            $socket->close();
        }
    });

    it('catches MultipartPartTooLargeException when a multipart boundary header exceeds maxHeaderSize', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            try {
                await($request->getParsedBody());

                return ServerResponse::plaintext('Should have failed');
            } catch (MultipartPartTooLargeException $e) {
                return new ServerResponse(413, [], 'Part header too big');
            } catch (Throwable $e) {
                return new ServerResponse(500, [], 'Wrong error thrown');
            }
        }, maxHeaderSize: 1024);

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $boundary = 'boundary123';
            $hugeName = str_repeat('A', 2048);

            $payload = "--{$boundary}\r\n" .
                "Content-Disposition: form-data; name=\"{$hugeName}\"\r\n\r\n" .
                "value\r\n" .
                "--{$boundary}--\r\n";

            $headers = "POST / HTTP/1.1\r\n" .
                "Host: localhost\r\n" .
                "Content-Type: multipart/form-data; boundary={$boundary}\r\n" .
                'Content-Length: ' . strlen($payload) . "\r\n\r\n";

            $responsePromise = new Promise(function ($resolve) use ($connection) {
                $buffer = '';
                $connection->on('data', function ($chunk) use (&$buffer, $resolve, $connection) {
                    $buffer .= $chunk;
                    if (str_contains($buffer, 'Part header too big')) {
                        $resolve($buffer);
                        $connection->close();
                    }
                });
            });

            $connection->write($headers . $payload);

            $rawResponse = await($responsePromise);

            expect($rawResponse)->toContain('HTTP/1.1 413')
                ->and($rawResponse)->toContain('Part header too big')
            ;
        } finally {
            $socket->close();
        }
    });

    it('supports uploading multiple files concurrently under a single parameter name (array and bracketless syntax)', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            try {
                $form = await($request->getParsedBody());
                $files = $form->getFiles('documents');
                $fileDetails = [];

                foreach ($files as $file) {
                    $fileDetails[] = [
                        'name' => $file->clientFilename,
                        'size' => $file->size,
                        'content' => file_get_contents($file->tmpPath),
                    ];
                }

                return ServerResponse::json(['files' => $fileDetails]);
            } catch (Throwable $e) {
                return new ServerResponse(500, [], $e->getMessage());
            }
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $boundary = 'boundary123';
            $payload = "--{$boundary}\r\n" .
                "Content-Disposition: form-data; name=\"documents[]\"; filename=\"doc1.txt\"\r\n" .
                "Content-Type: text/plain\r\n\r\n" .
                "content_of_doc1\r\n" .
                "--{$boundary}\r\n" .
                "Content-Disposition: form-data; name=\"documents\"; filename=\"doc2.txt\"\r\n" .
                "Content-Type: text/plain\r\n\r\n" .
                "content_of_doc2\r\n" .
                "--{$boundary}--\r\n";

            $httpRequest = "POST /multi-upload HTTP/1.1\r\n" .
                "Host: localhost\r\n" .
                "Content-Type: multipart/form-data; boundary={$boundary}\r\n" .
                'Content-Length: ' . strlen($payload) . "\r\n\r\n" .
                $payload;

            $responsePromise = new Promise(function ($resolve, $reject) use ($connection) {
                $buffer = '';
                $connection->on('data', function ($chunk) use (&$buffer, $resolve, $reject, $connection) {
                    $buffer .= $chunk;

                    if (str_contains($buffer, 'content_of_doc2')) {
                        $resolve($buffer);
                        $connection->close();
                    } elseif (str_contains($buffer, 'HTTP/1.1 500') || str_contains($buffer, 'HTTP/1.1 400')) {
                        $reject(new RuntimeException("Server failed with error response:\n" . $buffer));
                        $connection->close();
                    }
                });
            });

            $connection->write($httpRequest);

            $rawResponse = await($responsePromise);
            $jsonStart = strpos($rawResponse, "\r\n\r\n") + 4;
            $json = json_decode(substr($rawResponse, $jsonStart), true);

            expect($json['files'])->toHaveCount(2)
                ->and($json['files'][0]['name'])->toBe('doc1.txt')
                ->and($json['files'][0]['content'])->toBe('content_of_doc1')
                ->and($json['files'][1]['name'])->toBe('doc2.txt')
                ->and($json['files'][1]['content'])->toBe('content_of_doc2')
            ;
        } finally {
            $socket->close();
        }
    });

    it('successfully parses multipart body when the boundary parameter is enclosed in double quotes', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            try {
                $form = await($request->getParsedBody());

                return ServerResponse::json(['value' => $form->get('test_field')]);
            } catch (Throwable $e) {
                return new ServerResponse(500, [], $e->getMessage());
            }
        });

        try {
            $rawClient = new Connector();
            $connection = await($rawClient->connect(str_replace('http://', 'tcp://', $url)));

            $boundary = 'simple-boundary';
            $payload = "--{$boundary}\r\n" .
                "Content-Disposition: form-data; name=\"test_field\"\r\n\r\n" .
                "quoted_boundary_works\r\n" .
                "--{$boundary}--\r\n";

            $headers = "POST / HTTP/1.1\r\n" .
                "Host: localhost\r\n" .
                "Content-Type: multipart/form-data; boundary=\"{$boundary}\"\r\n" .
                'Content-Length: ' . strlen($payload) . "\r\n\r\n";

            $responsePromise = new Promise(function ($resolve, $reject) use ($connection) {
                $buffer = '';
                $connection->on('data', function ($chunk) use (&$buffer, $resolve, $reject, $connection) {
                    $buffer .= $chunk;
                    if (str_contains($buffer, 'quoted_boundary_works')) {
                        $resolve($buffer);
                        $connection->close();
                    } elseif (str_contains($buffer, 'HTTP/1.1 500')) {
                        $reject(new RuntimeException('Server failed with 500 error'));
                        $connection->close();
                    }
                });
            });

            $connection->write($headers . $payload);

            $rawResponse = await($responsePromise);
            expect($rawResponse)->toContain('quoted_boundary_works');
        } finally {
            $socket->close();
        }
    });

    it('handles 0-byte empty file uploads cleanly alongside normal text fields', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            try {
                $form = await($request->getParsedBody());
                $file = $form->getFile('empty_log');

                return ServerResponse::json([
                    'field' => $form->get('app_name'),
                    'has_file' => $file !== null,
                    'file_size' => $file ? $file->size : null,
                ]);
            } catch (Throwable $e) {
                return new ServerResponse(500, [], $e->getMessage());
            }
        });

        $tmpFile = tempnam(sys_get_temp_dir(), 'hibla_empty_');
        file_put_contents($tmpFile, '');

        try {
            $response = await(Http::client()
                ->multipartWithFiles(
                    data: ['app_name' => 'HiblaServer'],
                    files: ['empty_log' => $tmpFile]
                )
                ->post($url));

            expect($response->status())->toBe(200)
                ->and($response->json('field'))->toBe('HiblaServer')
                ->and($response->json('has_file'))->toBeTrue()
                ->and($response->json('file_size'))->toBe(0)
            ;
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
            $socket->close();
        }
    });

    it('streams uploaded files directly to an external service (S3 simulation) in-memory with zero local disk IO', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $contentType = $request->getHeaderLine('Content-Type');

            if (preg_match('/boundary=(?:"([^"]+)"|([^;,\s]+))/i', $contentType, $matches) !== 1) {
                return new ServerResponse(400, [], 'Missing boundary');
            }

            $boundary = $matches[1] !== '' ? $matches[1] : $matches[2];
            $parser = new MultipartParser($boundary);

            $s3UploadedData = '';
            $s3UploadedFilename = '';

            $s3UploadPromise = new Promise(function ($resolve, $reject) use ($parser, &$s3UploadedData, &$s3UploadedFilename) {
                $parser->on('file', function ($name, $filename, $mime, $fileStream) use (&$s3UploadedData, &$s3UploadedFilename, $reject) {
                    $s3UploadedFilename = $filename;
                    $fileStream->on('data', function (string $chunk) use (&$s3UploadedData) {
                        $s3UploadedData .= $chunk;
                    });

                    $fileStream->on('error', function (Throwable $e) use ($reject) {
                        $reject($e);
                    });
                });

                $parser->on('end', function () use ($resolve) {
                    $resolve(null);
                });

                $parser->on('error', function (Throwable $e) use ($reject) {
                    $reject($e);
                });
            });

            $request->getBody()->pipe($parser);

            try {
                await($s3UploadPromise);

                return ServerResponse::json([
                    'success' => true,
                    'target' => 's3://my-bucket/' . $s3UploadedFilename,
                    'bytes_received' => strlen($s3UploadedData),
                    'content_preview' => $s3UploadedData,
                ]);
            } catch (Throwable $e) {
                return new ServerResponse(500, [], $e->getMessage());
            }
        });

        $localClientFile = tempnam(sys_get_temp_dir(), 'client_side_');
        $filePayload = 'S3-Direct-Streaming-Multipart-Payload-Data-Check-123';
        file_put_contents($localClientFile, $filePayload);

        try {
            $response = await(Http::client()
                ->multipartWithFiles(
                    data: ['album' => 'vacation_2026'],
                    files: ['photo' => $localClientFile]
                )
                ->post($url . '/upload-to-s3'));

            expect($response->status())->toBe(200)
                ->and($response->json('success'))->toBeTrue()
                ->and($response->json('target'))->toBe('s3://my-bucket/' . basename($localClientFile))
                ->and($response->json('bytes_received'))->toBe(strlen($filePayload))
                ->and($response->json('content_preview'))->toBe($filePayload)
            ;
        } finally {
            if (file_exists($localClientFile)) {
                unlink($localClientFile);
            }
            $socket->close();
        }
    });

    it('streams uploaded files directly to an external service (S3 simulation) in-memory using streamMultipart with zero local disk IO', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $s3UploadedData = '';
            $s3UploadedFilename = '';
            $fields = [];

            try {
                await($request->streamMultipart(
                    onFile: function (string $name, string $filename, string $mime, $fileStream) use (&$s3UploadedData, &$s3UploadedFilename): void {
                        $s3UploadedFilename = $filename;

                        $collectPromise = new Promise(function ($resolve, $reject) use ($fileStream, &$s3UploadedData) {
                            $fileStream->on('data', function (string $chunk) use (&$s3UploadedData) {
                                $s3UploadedData .= $chunk;
                            });
                            $fileStream->on('end', function () use ($resolve) {
                                $resolve(null);
                            });
                            $fileStream->on('error', $reject);
                        });

                        await($collectPromise);
                    },
                    onField: function (string $name, string $value) use (&$fields): void {
                        $fields[$name] = $value;
                    }
                ));

                return ServerResponse::json([
                    'success' => true,
                    'target' => 's3://my-bucket/' . $s3UploadedFilename,
                    'bytes_received' => strlen($s3UploadedData),
                    'content_preview' => $s3UploadedData,
                    'album' => $fields['album'] ?? null,
                ]);
            } catch (Throwable $e) {
                return new ServerResponse(500, [], $e->getMessage());
            }
        });

        $localClientFile = tempnam(sys_get_temp_dir(), 'client_side_');
        $filePayload = 'S3-Direct-Streaming-Multipart-Payload-Data-Check-123';
        file_put_contents($localClientFile, $filePayload);

        try {
            $response = await(Http::client()
                ->multipartWithFiles(
                    data: ['album' => 'vacation_2026'],
                    files: ['photo' => $localClientFile]
                )
                ->post($url . '/upload-to-s3'));

            expect($response->status())->toBe(200)
                ->and($response->json('success'))->toBeTrue()
                ->and($response->json('target'))->toBe('s3://my-bucket/' . basename($localClientFile))
                ->and($response->json('bytes_received'))->toBe(strlen($filePayload))
                ->and($response->json('content_preview'))->toBe($filePayload)
                ->and($response->json('album'))->toBe('vacation_2026')
            ;
        } finally {
            if (file_exists($localClientFile)) {
                unlink($localClientFile);
            }
            $socket->close();
        }
    });

    it('streams uploaded files directly to an external service (S3 simulation) in-memory using promise-based streamMultipart with zero local disk IO', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            $s3UploadedData = '';
            $s3UploadedFilename = '';
            $fields = [];

            try {
                await($request->streamMultipart(
                    onFile: function (string $name, string $filename, string $mime, PromiseReadableStreamInterface $fileStream) use (&$s3UploadedData, &$s3UploadedFilename): void {
                        $s3UploadedFilename = $filename;
                        $s3UploadedData = await($fileStream->readAllAsync());
                    },
                    onField: function (string $name, string $value) use (&$fields): void {
                        $fields[$name] = $value;
                    }
                ));

                return ServerResponse::json([
                    'success' => true,
                    'target' => 's3://my-bucket/' . $s3UploadedFilename,
                    'bytes_received' => strlen($s3UploadedData),
                    'content_preview' => $s3UploadedData,
                    'album' => $fields['album'] ?? null,
                ]);
            } catch (Throwable $e) {
                return new ServerResponse(500, [], $e->getMessage());
            }
        });

        $localClientFile = tempnam(sys_get_temp_dir(), 'client_side_');
        $filePayload = 'S3-Promise-Direct-Streaming-Multipart-Payload-123';
        file_put_contents($localClientFile, $filePayload);

        try {
            $response = await(Http::client()
                ->multipartWithFiles(
                    data: ['album' => 'vacation_2026'],
                    files: ['photo' => $localClientFile]
                )
                ->post($url . '/upload-to-s3'));

            expect($response->status())->toBe(200)
                ->and($response->json('success'))->toBeTrue()
                ->and($response->json('target'))->toBe('s3://my-bucket/' . basename($localClientFile))
                ->and($response->json('bytes_received'))->toBe(strlen($filePayload))
                ->and($response->json('content_preview'))->toBe($filePayload)
                ->and($response->json('album'))->toBe('vacation_2026')
            ;
        } finally {
            if (file_exists($localClientFile)) {
                unlink($localClientFile);
            }
            $socket->close();
        }
    });
});

describe('Application Exception Handling (onError)', function () {
    
    it('returns a standard 500 Internal Server Error when no custom error handler is attached', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            throw new \RuntimeException('Database connection failed');
        });

        try {
            $response = await(Http::client()->get($url));

            expect($response->status())->toBe(500)
                ->and($response->body())->toContain('500 Internal Server Error')
                ->and($response->body())->toContain('Database connection failed')
            ;
        } finally {
            $socket->close();
        }
    });

    it('allows a custom error handler to render a custom response', function () {
        $errorHandler = function (\Throwable $e, ServerRequest $request) {
            return ServerResponse::json([
                'error' => true,
                'message' => $e->getMessage(),
                'path' => $request->getUri()
            ], 503);
        };

        [$socket, $url] = createTestServer(
            requestHandler: function (ServerRequest $request) {
                throw new \LogicException('Redis is down');
            },
            errorHandler: $errorHandler
        );

        try {
            $response = await(Http::client()->get($url . '/test-error'));

            expect($response->status())->toBe(503)
                ->and($response->header('content-type'))->toBe('application/json')
                ->and($response->json('error'))->toBeTrue()
                ->and($response->json('message'))->toBe('Redis is down')
                ->and($response->json('path'))->toBe('/test-error')
            ;
        } finally {
            $socket->close();
        }
    });

    it('falls back to the standard 500 error retaining the original exception if the custom handler returns null', function () {
        $errorHandler = function (\Throwable $e, ServerRequest $request) {
            return null;
        };

        [$socket, $url] = createTestServer(
            requestHandler: function (ServerRequest $request) {
                throw new \RuntimeException('Original Database Error');
            },
            errorHandler: $errorHandler
        );

        try {
            $response = await(Http::client()->get($url));

            expect($response->status())->toBe(500)
                ->and($response->body())->toContain('500 Internal Server Error')
                ->and($response->body())->toContain('Original Database Error')
                ->and($response->body())->not->toContain('Custom error handler must return')
            ;
        } finally {
            $socket->close();
        }
    });

    it('falls back to the standard 500 error if the custom error handler itself throws an exception', function () {
        $errorHandler = function (\Throwable $e, ServerRequest $request) {
            // Oh no, the error logger is also broken!
            throw new \RuntimeException('Error logger failed');
        };

        [$socket, $url] = createTestServer(
            requestHandler: function (ServerRequest $request) {
                throw new \LogicException('Initial application error');
            },
            errorHandler: $errorHandler
        );

        try {
            $response = await(Http::client()->get($url));

            expect($response->status())->toBe(500)
                ->and($response->body())->toContain('500 Internal Server Error')
                ->and($response->body())->toContain('Error logger failed')
            ;
        } finally {
            $socket->close();
        }
    });

    it('falls back to the standard 500 error if the custom error handler returns a non-Response object', function () {
        $errorHandler = function (\Throwable $e, ServerRequest $request) {
            return "This is a string, not a Response object!";
        };

        [$socket, $url] = createTestServer(
            requestHandler: function (ServerRequest $request) {
                throw new \Exception('Oops');
            },
            errorHandler: $errorHandler
        );

        try {
            $response = await(Http::client()->get($url));

            expect($response->status())->toBe(500)
                ->and($response->body())->toContain('500 Internal Server Error')
                ->and($response->body())->toContain('Custom error handler must return an instance of Response, or null to fallback')
            ;
        } finally {
            $socket->close();
        }
    });

    it('forcefully applies Connection: close to custom error responses to prevent state desynchronization', function () {
        $errorHandler = function (\Throwable $e, ServerRequest $request) {
            return ServerResponse::plaintext("Custom error page", 500);
        };

        [$socket, $url] = createTestServer(
            requestHandler: function (ServerRequest $request) {
                throw new \Exception('Oops');
            },
            errorHandler: $errorHandler
        );

        try {
            $response = await(Http::client()->get($url));

            expect($response->status())->toBe(500)
                ->and($response->body())->toBe('Custom error page')
                ->and(strtolower($response->header('connection')))->toBe('close')
            ;
        } finally {
            $socket->close();
        }
    });

});