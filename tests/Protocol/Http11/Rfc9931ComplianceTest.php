<?php

declare(strict_types=1);

use Hibla\HttpServer\Interfaces\ProtocolHandlerInterface;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\Response;
use Hibla\HttpServer\Protocol\Http11ProtocolHandler;

describe('RFC 9931 Section 8 — Requirements for HTTP CONNECT', function () {

    it('MUST close the connection when rejecting a CONNECT request to mitigate optimistic smuggling', function () {
        $buffer = '';
        $connection = mockConnection($buffer, expectClose: true);

        /** @var array<Request> $parsedRequests */
        $parsedRequests = [];

        $handler = new Http11ProtocolHandler($connection, function (Request $request, ProtocolHandlerInterface $protocol) use (&$parsedRequests) {
            $parsedRequests[] = $request;

            if ($request->getMethod() === 'CONNECT') {
                $protocol->writeResponse(new Response(403, [], 'Forbidden'));
            }
        });

        $raw = "CONNECT target.example:443 HTTP/1.1\r\n"
             . "Host: target.example:443\r\n"
             . "\r\n"
             . "POST /smuggled-endpoint HTTP/1.1\r\n"
             . "Host: localhost\r\n"
             . "Content-Length: 0\r\n\r\n";

        $handler->handleData($raw);
        expect($buffer)->toContain('Connection: close');
        ! expect($parsedRequests)->toHaveCount(1)
                    ->and($parsedRequests[0]->getMethod())->toBe('CONNECT')
        ;
    });

});
