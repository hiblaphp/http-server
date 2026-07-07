<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Internals;

use Hibla\EventLoop\Loop;
use Hibla\HttpServer\Exceptions\InvalidResponseException;
use Hibla\HttpServer\Interfaces\ConnectionManagerInterface;
use Hibla\HttpServer\Interfaces\ProtocolHandlerInterface;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\RequestBodyStream;
use Hibla\HttpServer\Message\Response;
use Hibla\HttpServer\Protocol\Http11ProtocolHandler;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Stream\Interfaces\ReadableStreamInterface;

/**
 * @internal
 */
final class Http11ConnectionManager implements ConnectionManagerInterface
{
    /**
     * @var list<Http11PipelineItem>
     */
    private array $pipelineQueue = [];

    private bool $isFlushing = false;

    private ?Http11ProtocolHandler $protocolHandler = null;

    /**
     * @var callable(Request, ProtocolHandlerInterface): (Response|null)
     */
    private $requestHandler;

    /**
     * @param callable(Request, ProtocolHandlerInterface): (Response|null) $requestHandler
     * @param (callable(\Throwable, Request): (Response|null))|null $errorHandler
     */
    public function __construct(
        callable $requestHandler,
        private readonly int $maxBodySize = 10485760,
        private readonly int $maxHeaderSize = 8192,
        private readonly int $maxHeaderCount = 100,
        private readonly ?float $headerTimeout = null,
        private readonly ?float $bodyTimeout = null,
        private readonly ?float $requestTimeout = null,
        private readonly ?float $keepAliveTimeout = null,
        private readonly int $maxConcurrentRequestsPerConnection = 128,
        private readonly int $maxUploadedFiles = 20,
        private readonly int $maxFormFields = 1000,
        private readonly mixed $errorHandler = null
    ) {
        $this->requestHandler = $requestHandler;
    }

    public function handle(ConnectionInterface $connection): void
    {
        $this->protocolHandler = new Http11ProtocolHandler(
            $connection,
            $this->onRequest(...),
            $this->maxBodySize,
            $this->maxHeaderSize,
            $this->maxHeaderCount,
            $this->headerTimeout,
            $this->bodyTimeout,
            $this->requestTimeout,
            $this->keepAliveTimeout,
            $this->maxUploadedFiles,
            $this->maxFormFields
        );

        $this->protocolHandler->onEarlyResponse = function (string $data) use ($connection): void {
            $item = new Http11PipelineItem();
            $item->isEarly = true;
            $item->data = $data;
            $item->isReady = true;

            $this->pipelineQueue[] = $item;

            if (\count($this->pipelineQueue) >= $this->maxConcurrentRequestsPerConnection) {
                $connection->pause();
            }

            $this->flushQueue();
        };

        $connection->on('close', function (): void {
            $this->pipelineQueue = [];
            $this->protocolHandler = null;
        });

        $connection->on('data', function (string $data): void {
            $this->protocolHandler?->handleData($data);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function gracefulShutdown(): void
    {
        $this->protocolHandler?->gracefulShutdown();
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveRequestsCount(): int
    {
        return $this->protocolHandler?->getActiveRequestsCount() ?? 0;
    }

    /**
     * {@inheritDoc}
     */
    public function isUpgraded(): bool
    {
        return $this->protocolHandler?->isUpgraded() ?? true;
    }

    private function onRequest(Request $request, ProtocolHandlerInterface $protocol): void
    {
        $item = new Http11PipelineItem();
        $this->pipelineQueue[] = $item;

        if (\count($this->pipelineQueue) >= $this->maxConcurrentRequestsPerConnection) {
            $protocol->getConnection()->pause();
        }

        $fiber = new \Fiber(function () use ($request, $protocol, $item): void {
            try {
                $response = ($this->requestHandler)($request, $protocol);

                if ($protocol->isUpgraded()) {
                    $item->response = null;

                    return;
                }

                if (! $response instanceof Response) {
                    throw new InvalidResponseException('Request handler must return an instance of Response');
                }

                $this->enforceBodyConsumptionSafety($request, $response);

                $item->response = $response;
            } catch (\Throwable $e) {
                $item->response = $protocol->isUpgraded() ? null : $this->resolveErrorResponse($e, $request);
            } finally {
                $item->isReady = true;
                $this->flushQueue();
            }
        });

        Loop::addFiber($fiber);
    }

    private function resolveErrorResponse(\Throwable $e, Request $request): Response
    {
        if ($this->errorHandler === null) {
            return $this->createFallbackErrorResponse($e);
        }

        try {
            $customResponse = ($this->errorHandler)($e, $request);

            if (! $customResponse instanceof Response) {
                return $this->createFallbackErrorResponse(
                    new InvalidResponseException('Custom error handler must return a Response object')
                );
            }

            // Uncaught exceptions mean internal state/streams might be unstable.
            // It is safer to forcefully close the connection to prevent smuggling or desyncs.
            if (! $customResponse->hasHeader('Connection')) {
                $customResponse->setHeader('Connection', 'close');
            }

            return $customResponse;
        } catch (\Throwable $handlerException) {
            return $this->createFallbackErrorResponse($handlerException);
        }
    }

    private function createFallbackErrorResponse(\Throwable $e): Response
    {
        $response = Response::plaintext("500 Internal Server Error\n" . $e->getMessage(), 500);
        $response->setHeader('Connection', 'close');

        return $response;
    }

    private function enforceBodyConsumptionSafety(Request $request, Response $response): void
    {
        $body = $request->getBody();

        if ($body instanceof RequestBodyStream) {
            if ($body->isReadable() && ! $body->hasDataListener()) {
                $response->setHeader('Connection', 'close');
                $body->close();
            }
        } elseif ($body instanceof ReadableStreamInterface && $body->isReadable()) {
            $response->setHeader('Connection', 'close');
            $body->close();
        }
    }

    private function flushQueue(): void
    {
        $protocolHandler = $this->protocolHandler;

        if ($this->isFlushing || $this->pipelineQueue === [] || $protocolHandler === null) {
            return;
        }

        $head = $this->pipelineQueue[0];
        if (! $head->isReady) {
            return;
        }

        $this->isFlushing = true;
        $connection = $protocolHandler->getConnection();

        $onComplete = function () use ($connection): void {
            array_shift($this->pipelineQueue);
            $this->isFlushing = false;

            if (\count($this->pipelineQueue) < $this->maxConcurrentRequestsPerConnection) {
                $connection->resume();
            }

            $this->flushQueue();
        };

        if ($head->isEarly) {
            if (\is_string($head->data)) {
                $connection->write($head->data);
            }
            $onComplete();
        } else {
            if ($head->response instanceof Response && ! $protocolHandler->isUpgraded()) {
                try {
                    $protocolHandler->writeResponse($head->response, $onComplete);
                } catch (\Throwable $e) {
                    try {
                        $errorResponse = $this->createFallbackErrorResponse($e);
                        $protocolHandler->writeResponse($errorResponse, $onComplete);
                    } catch (\Throwable) {
                        $connection->close();
                        $onComplete();
                    }
                }
            } else {
                $protocolHandler->decrementActiveRequests();
                $onComplete();
            }
        }
    }
}
