<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Http;
use Hibla\HttpServer\Message\Request as ServerRequest;
use Hibla\HttpServer\Message\Response as ServerResponse;

use function Hibla\await;

afterEach(function () {
    Loop::reset();
});

describe('Server Security Limits', function () {

    it('rejects real requests over TCP that exceed the maxHeaderCount limit with a 431', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('Should not reach here');
        }, maxHeaderCount: 5);

        try {
            $client = Http::client();
            for ($i = 0; $i < 10; $i++) {
                $client = $client->withHeader("X-Custom-{$i}", 'Value');
            }

            try {
                $response = await($client->get($url));
                expect($response->status())->toBe(431);
            } catch (NetworkException $e) {
                expect($e->getMessage())->not->toBeEmpty();
            }
        } finally {
            $socket->close();
        }
    });

    it('rejects requests exceeding maxBodySize with a 413 Payload Too Large', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('This should not be reached');
        }, maxBodySize: 1024);

        try {
            $largePayload = str_repeat('A', 2048);

            try {
                $response = await(Http::post($url, ['data' => $largePayload]));
                expect($response->status())->toBe(413);
            } catch (NetworkException $e) {
                expect($e->getMessage())->not->toBeEmpty();
            }
        } finally {
            $socket->close();
        }
    });

    it('rejects requests exceeding maxHeaderSize with a 431 Request Header Fields Too Large', function () {
        [$socket, $url] = createTestServer(function (ServerRequest $request) {
            return ServerResponse::plaintext('This should not be reached');
        }, maxHeaderSize: 1024);

        try {
            $massiveHeaderValue = str_repeat('B', 2048);

            try {
                $response = await(
                    Http::client()
                        ->withHeader('X-Massive-Header', $massiveHeaderValue)
                        ->get($url)
                );
                expect($response->status())->toBe(431);
            } catch (NetworkException $e) {
                expect($e->getMessage())->not->toBeEmpty();
            }
        } finally {
            $socket->close();
        }
    });

});
