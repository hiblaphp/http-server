<?php

declare(strict_types=1);

use Hibla\HttpServer\HttpServer;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\SocketServer;

uses()->afterEach(function () {
    gc_collect_cycles();
})->in(__DIR__);

function debug(string $message): void
{
    fwrite(STDERR, "[DEBUG] {$message}\n");
}

function getServerProperty(HttpServer $server, string $property): mixed
{
    $reflection = new ReflectionClass($server);

    return $reflection->getProperty($property)->getValue($server);
}

function mockConnection(
    string &$buffer,
    bool $expectClose = false,
    ?callable &$onData = null,
    ?int &$pauseCount = null,
    ?int &$resumeCount = null
): ConnectionInterface {
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('getRemoteAddress')->andReturn('127.0.0.1');

    $connection->shouldReceive('write')->zeroOrMoreTimes()->andReturnUsing(function (string $data) use (&$buffer) {
        $buffer .= $data;

        return true;
    });

    $connection->shouldReceive('end')->zeroOrMoreTimes()->andReturnUsing(function (?string $data = null) use (&$buffer, $connection) {
        if ($data !== null) {
            $buffer .= $data;
        }
        $connection->close();
    });

    $closeListeners = [];

    $connection->shouldReceive('on')->zeroOrMoreTimes()->andReturnUsing(function (string $event, callable $listener) use (&$closeListeners, &$onData) {
        if ($event === 'close') {
            $closeListeners[] = $listener;
        }
        if ($event === 'data') {
            $onData = $listener;
        }
    });

    $closeExpectation = $connection->shouldReceive('close');
    if ($expectClose) {
        $closeExpectation->atLeast()->once();
    } else {
        $closeExpectation->zeroOrMoreTimes();
    }

    $closeExpectation->andReturnUsing(function () use (&$closeListeners) {
        foreach ($closeListeners as $listener) {
            $listener();
        }
    });

    $connection->shouldReceive('pause')->zeroOrMoreTimes()->andReturnUsing(function () use (&$pauseCount) {
        if ($pauseCount !== null) {
            $pauseCount++;
        }
    });

    $connection->shouldReceive('resume')->zeroOrMoreTimes()->andReturnUsing(function () use (&$resumeCount) {
        if ($resumeCount !== null) {
            $resumeCount++;
        }
    });

    return $connection;
}

function mockStreamingConnection(string &$buffer): ConnectionInterface
{
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('getRemoteAddress')->andReturn('127.0.0.1');

    $connection->shouldReceive('write')->andReturnUsing(function (string $data) use (&$buffer) {
        $buffer .= $data;

        return true;
    });

    $connection->shouldReceive('pause')->zeroOrMoreTimes();
    $connection->shouldReceive('resume')->zeroOrMoreTimes();
    $connection->shouldReceive('removeListener')->zeroOrMoreTimes();

    $closeListeners = [];

    $connection->shouldReceive('on')->zeroOrMoreTimes()->andReturnUsing(function (string $event, callable $listener) use (&$closeListeners) {
        if ($event === 'close') {
            $closeListeners[] = $listener;
        }
    });

    $triggerClose = function () use (&$closeListeners) {
        foreach ($closeListeners as $listener) {
            $listener();
        }
    };

    $connection->shouldReceive('close')->zeroOrMoreTimes()->andReturnUsing($triggerClose);

    $connection->shouldReceive('end')->andReturnUsing(function (?string $data = null) use (&$buffer, $triggerClose) {
        if ($data !== null) {
            $buffer .= $data;
        }
        $triggerClose();
    });

    return $connection;
}

function createCloseableMockConnection(string &$buffer): ConnectionInterface
{
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('getRemoteAddress')->andReturn('127.0.0.1');

    $connection->shouldReceive('write')->andReturnUsing(function (string $data) use (&$buffer) {
        $buffer .= $data;

        return true;
    });

    $connection->shouldReceive('removeListener')->zeroOrMoreTimes();

    $closeListeners = [];
    $connection->shouldReceive('on')->zeroOrMoreTimes()->andReturnUsing(function (string $event, callable $listener) use (&$closeListeners) {
        if ($event === 'close') {
            $closeListeners[] = $listener;
        }
    });

    $triggerClose = function () use (&$closeListeners) {
        foreach ($closeListeners as $listener) {
            $listener();
        }
    };

    $connection->shouldReceive('close')->zeroOrMoreTimes()->andReturnUsing($triggerClose);

    $connection->shouldReceive('end')->andReturnUsing(function (?string $data = null) use (&$buffer, $triggerClose) {
        if ($data !== null) {
            $buffer .= $data;
        }
        $triggerClose();
    });

    return $connection;
}

/**
 * @return array{0: SocketServer, 1: string}
 */
function createTestServer(
    callable $requestHandler,
    int $maxBodySize = 10485760,
    int $maxHeaderSize = 8192,
    int $maxHeaderCount = 100,
    array $context = [],
    ?float $headerTimeout = null,
    ?float $keepAliveTimeout = null,
    int $maxConcurrentRequestsPerConnection = 128,
    int $maxUploadedFiles = 20,
    int $maxFormFields = 1000
): array {
    $scheme = isset($context['tls']) ? 'tls://' : 'tcp://';
    $socket = new SocketServer($scheme . '127.0.0.1:0', $context);

    HttpServer::attachProtocolHandler(
        $socket,
        $requestHandler,
        $maxBodySize,
        $maxHeaderSize,
        $maxHeaderCount,
        $headerTimeout,
        $keepAliveTimeout,
        $maxConcurrentRequestsPerConnection,
        $maxUploadedFiles,
        $maxFormFields
    );

    $url = str_replace(['tcp://', 'tls://'], ['http://', 'https://'], $socket->getAddress());

    return [$socket, $url];
}

function createMultipartPayload(string $boundary, array $fields, array $files): string
{
    $body = '';
    foreach ($fields as $name => $value) {
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
        $body .= "{$value}\r\n";
    }
    foreach ($files as $name => $file) {
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$file['filename']}\"\r\n";
        $body .= "Content-Type: {$file['mime']}\r\n\r\n";
        $body .= "{$file['content']}\r\n";
    }
    $body .= "--{$boundary}--\r\n";

    return $body;
}
