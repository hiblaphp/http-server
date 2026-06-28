<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Http;
use Hibla\HttpServer\Message\Request as ServerRequest;
use Hibla\HttpServer\Message\Response as ServerResponse;
use Hibla\Promise\Promise;

use function Hibla\await;

afterEach(function () {
    Loop::reset();
});

describe('Protocol Edge Cases', function () {

    it('seamlessly handles the Expect: 100-continue handshake', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('Body size: ' . strlen((string) $request->getBody()));
        });

        try {
            $payload = str_repeat('X', 50000);

            $response = await(
                Http::client()
                    ->withHeader('Expect', '100-continue')
                    ->body($payload)
                    ->post($url)
            );

            expect($response->status())->toBe(200)
                ->and($response->body())->toBe('Body size: 50000')
            ;
        } finally {
            $socket->close();
        }
    });

    it('processes diverse standard and custom HTTP methods safely', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext("Method used: {$request->getMethod()}");
        });

        try {
            $methods = ['PUT', 'PATCH', 'DELETE', 'OPTIONS', 'PURGE', 'MKCOL'];
            $promises = [];

            foreach ($methods as $method) {
                $promises[] = Http::client()->send($method, $url);
            }

            $responses = await(Promise::all($promises));

            foreach ($responses as $index => $response) {
                expect($response->status())->toBe(200)
                    ->and($response->body())->toBe("Method used: {$methods[$index]}")
                ;
            }
        } finally {
            $socket->close();
        }
    });

    it('downgrades to HTTP/1.0 and closes the connection if requested by the client', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('Protocol: ' . $request->getProtocolVersion());
        });

        try {
            $response = await(
                Http::client()
                    ->withCurlOption(CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0)
                    ->get($url)
            );

            expect($response->status())->toBe(200)
                ->and($response->getHttpVersion())->toBe('1.0')
                ->and($response->body())->toBe('Protocol: 1.0')
            ;
        } finally {
            $socket->close();
        }
    });

});
