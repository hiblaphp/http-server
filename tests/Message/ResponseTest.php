<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\HttpServer\Exceptions\JsonEncodingException;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\Response;
use Hibla\HttpServer\Message\SseStream;
use Hibla\Stream\Interfaces\ReadableStreamInterface;

it('automatically sets the correct reason phrase', function () {
    $responseOk = new Response(200);
    expect($responseOk->statusCode)->toBe(200)
        ->and($responseOk->reasonPhrase)->toBe('OK')
    ;

    $responseNotFound = new Response(404);
    expect($responseNotFound->statusCode)->toBe(404)
        ->and($responseNotFound->reasonPhrase)->toBe('Not Found')
    ;

    $responseCustom = new Response(418, [], '', 'I am a coffee pot');
    expect($responseCustom->reasonPhrase)->toBe('I am a coffee pot');
});

it('normalizes headers on instantiation', function () {
    $response = new Response(200, [
        'Content-Type' => 'text/plain',
        'X-Multiple' => ['A', 'B'],
    ]);

    $headers = $response->headers;

    expect($headers)->toHaveKey('content-type')
        ->and($headers['content-type'])->toBe(['text/plain'])
        ->and($headers['x-multiple'])->toBe(['A', 'B'])
    ;
});

it('can overwrite headers via setHeader', function () {
    $response = new Response(200, ['Content-Type' => 'text/html']);

    $response->setHeader('content-type', 'application/json');
    $response->setHeader('X-New', ['1', '2']);

    expect($response->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($response->getHeaderLine('X-New'))->toBe('1, 2')
    ;
});

it('can append values to existing headers via addHeader', function () {
    $response = new Response(200, ['Set-Cookie' => 'session=123']);

    $response->addHeader('Set-Cookie', 'theme=dark');
    $response->addHeader('Set-Cookie', ['lang=en', 'track=0']);

    expect($response->headers['set-cookie'])->toBe([
        'session=123',
        'theme=dark',
        'lang=en',
        'track=0',
    ]);
});

it('creates plaintext responses via factory', function () {
    $response = Response::plaintext('Hello World', 201);

    expect($response->statusCode)->toBe(201)
        ->and($response->getHeaderLine('Content-Type'))->toBe('text/plain; charset=utf-8')
        ->and($response->body)->toBe('Hello World')
    ;
});

it('creates json responses via factory', function () {
    $data = ['id' => 1, 'name' => 'Test'];
    $response = Response::json($data, 200);

    expect($response->statusCode)->toBe(200)
        ->and($response->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($response->body)->toContain('"name": "Test"')
    ;
});

it('throws an exception on invalid json data', function () {
    $resource = fopen('php://memory', 'r');

    expect(fn() => Response::json($resource))
        ->toThrow(JsonEncodingException::class, 'Unable to encode given data as JSON');
});

it('creates html responses via factory', function () {
    $html = '<h1>Title</h1>';
    $response = Response::html($html, 403);

    expect($response->statusCode)->toBe(403)
        ->and($response->getHeaderLine('Content-Type'))->toBe('text/html; charset=utf-8')
        ->and($response->body)->toBe($html)
    ;
});

it('falls back to Unknown for unrecognized status codes', function () {
    $response = new Response(999);

    expect($response->statusCode)->toBe(999)
        ->and($response->reasonPhrase)->toBe('Unknown')
    ;
});

it('completely overwrites existing values when using setHeader', function () {
    $response = new Response(200, ['Cache-Control' => 'public, max-age=3600']);

    $response->setHeader('Cache-Control', 'no-store');
    expect($response->getHeader('cache-control'))->toBe(['no-store']);

    $response->setHeader('Cache-Control', ['no-cache', 'must-revalidate']);
    expect($response->getHeader('cache-control'))->toBe(['no-cache', 'must-revalidate']);
});

it('creates the header if it does not exist when using addHeader', function () {
    $response = new Response(200);

    expect($response->headers)->toBeEmpty();

    $response->addHeader('X-New-Header', 'FirstValue');

    expect($response->getHeaderLine('X-New-Header'))->toBe('FirstValue');
});

it('creates valid Server-Sent Events (SSE) responses via factory', function () {
    $response = Response::sse(function (SseStream $stream) {
        // Dummy emitter
    });

    expect($response->statusCode)->toBe(200)
        ->and($response->getHeaderLine('Content-Type'))->toBe('text/event-stream')
        ->and($response->getHeaderLine('Cache-Control'))->toBe('no-cache')
        ->and($response->getHeaderLine('Connection'))->toBe('keep-alive')
        ->and($response->getHeaderLine('X-Accel-Buffering'))->toBe('no')
    ;

    expect($response->body)->toBeInstanceOf(SseStream::class)
        ->and($response->body->isReadable())->toBeTrue()
    ;
});

it('can accept a readable stream as a response body', function () {
    $dummyStream = Mockery::mock(ReadableStreamInterface::class);

    $response = new Response();
    $response->setBody($dummyStream);

    expect($response->body)->toBeInstanceOf(ReadableStreamInterface::class)
        ->and($response->body)->toBe($dummyStream)
    ;
});

it('creates valid redirect responses via factory', function () {
    $response = Response::redirect('https://example.com');
    expect($response->statusCode)->toBe(302)
        ->and($response->getHeaderLine('Location'))->toBe('https://example.com')
    ;

    $response301 = Response::redirect('/home', 301);
    expect($response301->statusCode)->toBe(301)
        ->and($response301->getHeaderLine('Location'))->toBe('/home')
    ;
});

describe('Response::file', function () {
    it('creates a 404 plaintext response when the file does not exist', function () {
        $response = Response::file('/non/existent/file.txt');
        expect($response->statusCode)->toBe(404)
            ->and($response->getHeaderLine('Content-Type'))->toBe('text/plain; charset=utf-8')
            ->and($response->body)->toBe('File Not Found')
        ;
    });

    it('creates an asynchronous streaming response for an existing file', function () {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_response_');
        file_put_contents($tempFile, 'Hello, streaming world!');

        $response = Response::file($tempFile);

        expect($response->statusCode)->toBe(200)
            ->and($response->getHeaderLine('Content-Type'))->toBe('application/octet-stream')
            ->and($response->getHeaderLine('Content-Length'))->toBe((string) filesize($tempFile))
            ->and($response->getHeaderLine('Accept-Ranges'))->toBe('bytes')
            ->and($response->body)->toBeInstanceOf(ReadableStreamInterface::class)
        ;

        $response->body->close();
        @unlink($tempFile);
    });

    it('detects correct content type based on file extension', function () {
        $tempCss = sys_get_temp_dir() . '/test_style.css';
        file_put_contents($tempCss, 'body { color: red; }');

        $response = Response::file($tempCss);
        expect($response->getHeaderLine('Content-Type'))->toBe('text/css; charset=utf-8');

        $response->body->close();
        @unlink($tempCss);
    });

    it('handles valid HTTP Range requests and returns HTTP 206', function () {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_range_');
        $content = 'abcdefghij';
        file_put_contents($tempFile, $content);

        $request = new Request(
            method: 'GET',
            uri: '/',
            headers: ['Range' => 'bytes=2-5']
        );

        $response = Response::file($tempFile, $request);

        expect($response->statusCode)->toBe(206)
            ->and($response->getHeaderLine('Content-Range'))->toBe('bytes 2-5/10')
            ->and($response->getHeaderLine('Content-Length'))->toBe('4')
            ->and($response->body)->toBeInstanceOf(ReadableStreamInterface::class)
        ;
        $dataReceived = '';
        $stream = $response->body;
        $stream->on('data', function (string $chunk) use (&$dataReceived) {
            $dataReceived .= $chunk;
        });

        $stream->resume();

        Loop::run();

        expect($dataReceived)->toBe('cdef');

        @unlink($tempFile);
    });

    it('streams a large file (100MB) with a minimal memory footprint', function () {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_large_file_');

        $fp = fopen($tempFile, 'wb');

        $oneMegabyteChunk = str_repeat('a', 1024 * 1024); 

        for ($i = 0; $i < 100; $i++) {
            fwrite($fp, $oneMegabyteChunk);
        }

        fclose($fp);

        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        
        $startPeakMemory = memory_get_peak_usage();

        $response = Response::file($tempFile);
        expect($response->statusCode)->toBe(200)
            ->and($response->getHeaderLine('Content-Length'))->toBe('104857600'); 

        $bytesRead = 0;

        $stream = $response->body;

        $stream->on('data', function (string $chunk) use (&$bytesRead) {
            $bytesRead += strlen($chunk);
        });

        $stream->resume();

        Loop::run();

        expect($bytesRead)->toBe(100 * 1024 * 1024);

        $endPeakMemory = memory_get_peak_usage();
        $memorySpike = $endPeakMemory - $startPeakMemory;

        if (file_exists($tempFile)) {
            unlink($tempFile);
        }

        expect($memorySpike)->toBeLessThan(10 * 1024 * 1024);
    });
});
