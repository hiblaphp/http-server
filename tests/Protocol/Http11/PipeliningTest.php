<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\HttpServer\Internals\Http11ConnectionManager;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\Response;
use Hibla\Promise\Promise;

use function Hibla\await;
use function Hibla\delay;

afterEach(function () {
    Loop::reset();
    Mockery::close();
});

describe('HTTP/1.1 Pipelining (RFC 9112 §9.3) Compliance', function () {

    it('enforces strict FIFO response ordering even if later requests finish faster', function () {
        $buffer = '';
        $onData = null;

        $connection = mockConnection($buffer, onData: $onData);

        $manager = new Http11ConnectionManager(
            function (Request $request) {
                if ($request->getUri() === '/slow') {
                    await(delay(0.04));

                    return Response::plaintext('Slow Response');
                }

                return Response::plaintext('Fast Response');
            }
        );

        $manager->handle($connection);

        expect($onData)->not->toBeNull();

        $onData(
            "GET /slow HTTP/1.1\r\nHost: localhost\r\n\r\n" .
            "GET /fast HTTP/1.1\r\nHost: localhost\r\n\r\n"
        );

        await(delay(0.06));

        expect($buffer)->toContain('Slow Response')
            ->and($buffer)->toContain('Fast Response')
        ;

        $slowPos = strpos($buffer, 'Slow Response');
        $fastPos = strpos($buffer, 'Fast Response');

        expect($slowPos)->toBeLessThan($fastPos);
    });

    it('applies connection backpressure when pipeline depth exceeds the configured limit', function () {
        $buffer = '';
        $onData = null;
        $pauseCount = 0;
        $resumeCount = 0;

        $connection = mockConnection(
            buffer: $buffer,
            onData: $onData,
            pauseCount: $pauseCount,
            resumeCount: $resumeCount
        );

        $deferreds = [];

        $manager = new Http11ConnectionManager(
            function (Request $request) use (&$deferreds) {
                $uri = $request->getUri();

                return await(new Promise(function ($resolve) use (&$deferreds, $uri) {
                    $deferreds[$uri] = $resolve;
                }));
            },
            maxConcurrentRequestsPerConnection: 2
        );

        $manager->handle($connection);

        expect($onData)->not->toBeNull();

        debug('Sending 3 pipelined requests with limit = 2...');

        $onData(
            "GET /req1 HTTP/1.1\r\nHost: localhost\r\n\r\n" .
            "GET /req2 HTTP/1.1\r\nHost: localhost\r\n\r\n" .
            "GET /req3 HTTP/1.1\r\nHost: localhost\r\n\r\n"
        );

        await(delay(0.01));

        expect($pauseCount)->toBe(2);

        debug('Resolving /req1...');
        $deferreds['/req1'](Response::plaintext('Response 1'));
        await(delay(0.01));

        expect($resumeCount)->toBe(0);

        $deferreds['/req2'](Response::plaintext('Response 2'));
        await(delay(0.01));

        expect($resumeCount)->toBe(1);
    });

    it('retains correct sequencing and recovers even if a pipelined request throws an error', function () {
        $buffer = '';
        $onData = null;

        $connection = mockConnection($buffer, onData: $onData);

        $manager = new Http11ConnectionManager(
            function (Request $request) {
                if ($request->getUri() === '/error') {
                    throw new RuntimeException('Intentional Error');
                }

                return Response::plaintext('Clean Response');
            }
        );

        $manager->handle($connection);

        expect($onData)->not->toBeNull();

        $onData(
            "GET /error HTTP/1.1\r\nHost: localhost\r\n\r\n" .
            "GET /clean HTTP/1.1\r\nHost: localhost\r\n\r\n"
        );

        await(delay(0.02));

        expect($buffer)->toContain('500 Internal Server Error')
            ->and($buffer)->toContain('Clean Response')
        ;

        $errPos = strpos($buffer, '500 Internal Server Error');
        $cleanPos = strpos($buffer, 'Clean Response');

        expect($errPos)->toBeLessThan($cleanPos);
    });
});
