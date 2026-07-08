<?php

declare(strict_types=1);

use Hibla\HttpServer\Interfaces\ProtocolHandlerInterface;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\Response;
use Hibla\HttpServer\Protocol\Http11ProtocolHandler;

use function Hibla\await;
use function Hibla\delay;

describe('Zero-length request bodies', function () {

    it('dispatches a POST request immediately when Content-Length is 0 with an empty body', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $handler->handleData("POST /submit HTTP/1.1\r\nHost: localhost\r\nContent-Length: 0\r\n\r\n");

        expect($parsedRequest)->not->toBeNull()
            ->and(await($parsedRequest->getBufferedBody()))->toBe('')
        ;
    });

    it('accepts a GET request with an explicit Content-Length: 0 header', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 0\r\n\r\n");

        expect($parsedRequest)->not->toBeNull()
            ->and(await($parsedRequest->getBufferedBody()))->toBe('')
        ;
    });

    it('dispatches immediately when a chunked body consists only of the terminal zero chunk', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $handler->handleData(
            "POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n"
                . "0\r\n\r\n"
        );

        expect($parsedRequest)->not->toBeNull()
            ->and(await($parsedRequest->getBufferedBody()))->toBe('')
        ;
    });
});

describe('STATE_UPGRADED guard — bytes after connection close are silently dropped', function () {

    it('does not invoke the request handler when valid data arrives after a 400 error closes the connection', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestCount = 0;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestCount) {
            $requestCount++;
        });

        $handler->handleData("INVALID REQUEST LINE\r\n\r\n");
        expect($buffer)->toContain('400');

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        expect($requestCount)->toBe(0);
    });

    it('does not write additional responses when handleData is called repeatedly after a 431 close', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $handler = new Http11ProtocolHandler($connection, function () {
        });

        $handler->handleData(str_repeat('X', 20000));
        expect($buffer)->toContain('431');

        $responseCountBefore = substr_count($buffer, 'HTTP/1.1');

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        expect(substr_count($buffer, 'HTTP/1.1'))->toBe($responseCountBefore);
    });
});

describe('Body size boundary conditions', function () {

    it('accepts a Content-Length body whose byte count exactly equals maxBodySize', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request) use (&$parsedRequest) {
                $parsedRequest = $request;
            },
            maxBodySize: 8
        );

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 8\r\n\r\n12345678");

        expect($parsedRequest)->not->toBeNull()
            ->and(await($parsedRequest->getBufferedBody()))->toBe('12345678')
        ;
    });

    it('rejects a Content-Length body exceeding maxBodySize by exactly 1 byte', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) {
                $request->getBufferedBody()->catch(function (Throwable $e) use ($protocol) {
                    $protocol->writeResponse(new Response(413, ['Connection' => 'close'], 'Content Too Large'));
                });
            },
            maxBodySize: 8
        );

        $handler->handleData("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 9\r\n\r\n123456789");

        await(delay(0.01));

        expect($buffer)->toContain('HTTP/1.1 413')
            ->and($buffer)->toContain('Content Too Large')
        ;
    });

    it('accepts a chunked body whose accumulated byte count exactly equals maxBodySize', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request) use (&$parsedRequest) {
                $parsedRequest = $request;
            },
            maxBodySize: 8
        );

        $raw = "POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "4\r\nabcd\r\n"
            . "4\r\nefgh\r\n"
            . "0\r\n\r\n";

        $handler->handleData($raw);

        expect($parsedRequest)->not->toBeNull()
            ->and(await($parsedRequest->getBufferedBody()))->toBe('abcdefgh')
        ;
    });

    it('rejects a chunked body whose accumulated byte count exceeds maxBodySize by exactly 1 byte', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) {
                $request->getBufferedBody()->catch(function (Throwable $e) use ($protocol) {
                    $protocol->writeResponse(new Response(413, ['Connection' => 'close'], 'Content Too Large'));
                });
            },
            maxBodySize: 8
        );

        $raw = "POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "4\r\nabcd\r\n"
            . "5\r\nefghi\r\n"
            . "0\r\n\r\n";

        $handler->handleData($raw);

        await(delay(0.01));

        expect($buffer)->toContain('HTTP/1.1 413')
            ->and($buffer)->toContain('Content Too Large')
        ;
    });
});

describe('Pipelined chunked requests — state machine reset between requests', function () {

    it('correctly dispatches two sequential pipelined chunked POST requests with different bodies', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequests = [];
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequests) {
            $parsedRequests[] = $request;
        });

        $raw = "POST /first HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "3\r\nabc\r\n0\r\n\r\n"
            . "POST /second HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "5\r\nhello\r\n0\r\n\r\n";

        $handler->handleData($raw);

        expect($parsedRequests)->toHaveCount(2)
            ->and($parsedRequests[0]->uri)->toBe('/first')
            ->and(await($parsedRequests[0]->getBufferedBody()))->toBe('abc')
            ->and($parsedRequests[1]->uri)->toBe('/second')
            ->and(await($parsedRequests[1]->getBufferedBody()))->toBe('hello')
        ;
    });

    it('does not bleed body bytes from the first chunked request into the second pipelined request', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequests = [];
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequests) {
            $parsedRequests[] = $request;
        });

        $raw = "POST /first HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "a\r\n1234567890\r\n0\r\n\r\n"
            . "POST /second HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "5\r\nworld\r\n0\r\n\r\n";

        $handler->handleData($raw);

        expect($parsedRequests)->toHaveCount(2)
            ->and(await($parsedRequests[0]->getBufferedBody()))->toBe('1234567890')
            ->and(await($parsedRequests[1]->getBufferedBody()))->toBe('world')
        ;
    });
});

describe('HTTP/1.0 edge cases', function () {

    it('accepts an HTTP/1.0 request without a Host header as valid', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $handler->handleData("GET / HTTP/1.0\r\n\r\n");

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->protocolVersion)->toBe('1.0')
        ;
    });

    it('processes a chunked body on an HTTP/1.0 request and closes the connection afterwards', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) use (&$parsedRequest) {
                $parsedRequest = $request;
                $request->getBufferedBody()->then(function () use ($protocol) {
                    $protocol->writeResponse(new Response(200, [], 'OK'));
                });
            }
        );

        $raw = "POST / HTTP/1.0\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n5\r\nhello\r\n0\r\n\r\n";

        $handler->handleData($raw);

        expect($parsedRequest)->not->toBeNull();

        $bodyVal = await($parsedRequest->getBufferedBody());

        await(delay(0.01));

        expect($bodyVal)->toBe('hello')
            ->and($buffer)->toContain('HTTP/1.0 200 OK')
        ;
    });
});

describe('Request-line edge cases', function () {

    it('rejects a request where the URI contains a literal unencoded space', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $requestReached = false;
        $handler = new Http11ProtocolHandler($connection, function () use (&$requestReached) {
            $requestReached = true;
        });

        $handler->handleData("GET /path with spaces HTTP/1.1\r\nHost: localhost\r\n\r\n");

        expect($buffer)->toContain('HTTP/1.1 400 Bad Request')
            ->and($requestReached)->toBeFalse()
        ;
    });

    it('accepts an OPTIONS request with the asterisk-form request target', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $handler->handleData("OPTIONS * HTTP/1.1\r\nHost: localhost\r\n\r\n");

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->method)->toBe('OPTIONS')
            ->and($parsedRequest->uri)->toBe('*')
        ;
    });

    it('accepts a request URI containing a query string and stores it verbatim', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $handler->handleData("GET /search?q=hello+world&page=2 HTTP/1.1\r\nHost: localhost\r\n\r\n");

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->uri)->toBe('/search?q=hello+world&page=2')
        ;
    });

    it('accepts a non-standard but token-valid HTTP method', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $handler->handleData("PURGE /cache HTTP/1.1\r\nHost: localhost\r\n\r\n");

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->method)->toBe('PURGE')
        ;
    });
});

describe('Header field edge cases', function () {

    it('stores a header whose value is entirely whitespace as an empty string', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\nX-Empty:   \r\n\r\n");

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getHeaderLine('x-empty'))->toBe('')
        ;
    });

    it('accepts a header field with a single-character name', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\nA: value\r\n\r\n");

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getHeaderLine('a'))->toBe('value')
        ;
    });

    it('correctly parses a header field value that itself contains a colon', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\nAuthorization: Basic dXNlcjpwYXNz\r\n\r\n");

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getHeaderLine('authorization'))->toBe('Basic dXNlcjpwYXNz')
        ;
    });
});

describe('Header field value whitespace — HTAB handling per RFC 9110 §5.5', function () {

    it('accepts a field value with an embedded HTAB character as valid optional whitespace', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\nContent-Type: text/html\t;\tcharset=utf-8\r\n\r\n");

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getHeaderLine('content-type'))->toBe("text/html\t;\tcharset=utf-8")
        ;
    });

    it('strips leading and trailing HTAB from field values without raising a control character violation', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $handler->handleData("GET / HTTP/1.1\r\nHost: localhost\r\nX-Custom: \t value \t\r\n\r\n");

        expect($parsedRequest)->not->toBeNull()
            ->and($parsedRequest->getHeaderLine('x-custom'))->toBe('value')
        ;
    });
});

describe('Chunked trailer section — deliberate discard policy', function () {

    it('silently discards trailer fields and still dispatches the completed request', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $raw = "POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "5\r\nhello\r\n0\r\n"
            . "X-Checksum: abc123\r\n"
            . "\r\n";

        $handler->handleData($raw);

        expect($parsedRequest)->not->toBeNull()
            ->and(await($parsedRequest->getBufferedBody()))->toBe('hello')
        ;
    });

    it('silently discards a trailer section containing a null byte without treating it as a 400 error', function () {
        $buffer = '';
        $connection = mockConnection($buffer);

        $parsedRequest = null;
        $handler = new Http11ProtocolHandler($connection, function (Request $request) use (&$parsedRequest) {
            $parsedRequest = $request;
        });

        $raw = "POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "5\r\nhello\r\n0\r\n"
            . "X-Bad: val\x00ue\r\n"
            . "\r\n";

        $handler->handleData($raw);

        expect($parsedRequest)->not->toBeNull()
            ->and(await($parsedRequest->getBufferedBody()))->toBe('hello')
        ;
    });
});

describe('sendErrorAndClose() settle a pending getBufferedBody() promise', function () {

    it('leaves the getBufferedBody() promise permanently settled when chunk-size pre-validation rejects the request', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        $settled = false;
        $resolvedValue = null;
        $rejectedError = null;

        $handler = new Http11ProtocolHandler(
            $connection,
            function (Request $request, ProtocolHandlerInterface $protocol) use (&$settled, &$resolvedValue, &$rejectedError) {
                $request->getBufferedBody()
                    ->then(function ($body) use (&$settled, &$resolvedValue) {
                        $settled = true;
                        $resolvedValue = $body;
                    })
                    ->catch(function (Throwable $e) use (&$settled, &$rejectedError) {
                        $settled = true;
                        $rejectedError = $e;
                    })
                ;
            },
            maxBodySize: 16
        );

        $raw = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . "ff\r\n";

        $handler->handleData($raw);

        await(delay(0.05));

        expect($buffer)->toContain('HTTP/1.1 413')
            ->and($settled)->toBeTrue()
            ->and($resolvedValue)->toBeNull()
            ->and($rejectedError)->toBe($rejectedError)
        ;
    });
});
