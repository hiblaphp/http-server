<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Internals;

use Hibla\EventLoop\Loop;
use Hibla\HttpServer\Exceptions\InvalidResponseException;
use Hibla\HttpServer\Interfaces\ConnectionManagerInterface;
use Hibla\HttpServer\Interfaces\ProtocolHandlerInterface;
use Hibla\HttpServer\Message\Request;
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
     * @param int $maxBodySize
     * @param int $maxHeaderSize
     * @param int $maxHeaderCount
     * @param float|null $headerTimeout
     * @param float|null $bodyTimeout
     * @param float|null $requestTimeout
     * @param float|null $keepAliveTimeout
     * @param int $maxConcurrentRequestsPerConnection
     * @param int $maxUploadedFiles
     * @param int $maxFormFields
     * @param (callable(\Throwable, Request): (Response|null))|null $errorHandler
     * @param (callable(Request): void)|null $onClientDisconnect
     * @param int|null $keepAliveMaxRequests Maximum requests per connection before closing.
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
        private readonly mixed $errorHandler = null,
        private readonly mixed $onClientDisconnect = null,
        private readonly ?int $keepAliveMaxRequests = null
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
            $this->maxFormFields,
            $this->keepAliveMaxRequests
        );

        $connection->on('close', function (): void {
            // Trigger disconnects for any requests that haven't finished processing
            foreach ($this->pipelineQueue as $item) {
                if ($item->disconnectTrigger !== null) {
                    ($item->disconnectTrigger)();
                }

                if ($this->onClientDisconnect !== null && $item->request !== null) {
                    $req = $item->request;
                    Loop::addFiber(new \Fiber(function () use ($req): void {
                        try {
                            ($this->onClientDisconnect)($req);
                        } catch (\Throwable) {
                        }
                    }));
                }
            }

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
        if ($this->protocolHandler === null) {
            return 0;
        }

        return $this->protocolHandler->activeRequestsCount;
    }

    /**
     * {@inheritDoc}
     */
    public function isUpgraded(): bool
    {
        return $this->protocolHandler?->isUpgraded() ?? true;
    }

    private function onRequest(Request $request, ProtocolHandlerInterface $protocol, ?\Closure $disconnectTrigger = null): void
    {
        $item = new Http11PipelineItem();
        $item->request = $request;
        $item->disconnectTrigger = $disconnectTrigger;

        $body = $request->body;

        if ($body instanceof RequestBodyStream && $body->onStartReading !== null) {
            $body->onStartReading = function () use ($item) {
                $item->earlyResponse = "HTTP/1.1 100 Continue\r\n\r\n";
                $this->flushQueue();
            };
        }

        $this->pipelineQueue[] = $item;

        if (\count($this->pipelineQueue) >= $this->maxConcurrentRequestsPerConnection) {
            $protocol->connection->pause();
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

            if ($customResponse === null) {
                return $this->createFallbackErrorResponse($e);
            }

            if (! $customResponse instanceof Response) {
                return $this->createFallbackErrorResponse(
                    new InvalidResponseException('Custom error handler must return an instance of Response, or null to fallback to default.')
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
        $body = $request->body;

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
        $connection = $protocolHandler->connection;

        // Ensure 100 Continue (if applicable) is sent strictly in sequence
        if ($head->earlyResponse !== null && ! $head->earlyResponseSent) {
            $head->earlyResponseSent = true;
            $connection->write($head->earlyResponse);
        }

        // Wait for the final response to be ready
        if (! $head->isReady) {
            return;
        }

        $this->isFlushing = true;

        $onComplete = function () use ($connection): void {
            $popped = array_shift($this->pipelineQueue);

            // Memory Leak Prevention: Sever references so GC can collect the Request/Response
            if ($popped !== null) {
                $popped->request = null;
                $popped->disconnectTrigger = null;
                $popped->response = null;
                $popped->earlyResponse = null;
            }

            $this->isFlushing = false;

            if (\count($this->pipelineQueue) < $this->maxConcurrentRequestsPerConnection) {
                $connection->resume();
            }

            $this->flushQueue();
        };

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
