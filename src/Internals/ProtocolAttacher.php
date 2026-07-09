<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Internals;

use Hibla\HttpServer\Interfaces\ConnectionManagerInterface;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\Interfaces\ServerInterface;

/**
 * @internal This used for internal implementation and testing purposes
 */
final class ProtocolAttacher
{
    /**
     * Attaches the protocol handler to the socket server.
     *
     * @return callable():int A callback that triggers graceful shutdown and returns the active connection count.
     */
    public static function attach(
        ServerInterface $socket,
        callable $requestHandler,
        int $maxBodySize = 10485760,
        int $maxHeaderSize = 8192,
        int $maxHeaderCount = 100,
        ?float $headerTimeout = null,
        ?float $bodyTimeout = null,
        ?float $requestTimeout = null,
        ?float $keepAliveTimeout = null,
        int $maxConcurrentRequestsPerConnection = 128,
        int $maxUploadedFiles = 20,
        int $maxFormFields = 1000,
        ?callable $errorHandler = null,
        ?callable $onClientDisconnect = null,
        ?int $keepAliveMaxRequests = null
    ): callable {
        /** @var array<int, ConnectionManagerInterface> $activeManagers */
        $activeManagers = [];

        $socket->on('connection', static function (ConnectionInterface $connection) use (
            $requestHandler,
            $maxBodySize,
            $maxHeaderSize,
            $maxHeaderCount,
            $headerTimeout,
            $bodyTimeout,
            $requestTimeout,
            $keepAliveTimeout,
            $maxConcurrentRequestsPerConnection,
            &$activeManagers,
            $maxUploadedFiles,
            $maxFormFields,
            $errorHandler,
            $onClientDisconnect,
            $keepAliveMaxRequests,
        ): void {

            $manager = new Http11ConnectionManager(
                $requestHandler,
                $maxBodySize,
                $maxHeaderSize,
                $maxHeaderCount,
                $headerTimeout,
                $bodyTimeout,
                $requestTimeout,
                $keepAliveTimeout,
                $maxConcurrentRequestsPerConnection,
                $maxUploadedFiles,
                $maxFormFields,
                $errorHandler,
                $onClientDisconnect,
                $keepAliveMaxRequests
            );

            $managerId = spl_object_id($manager);
            $activeManagers[$managerId] = $manager;

            $connection->on('close', static function () use ($managerId, &$activeManagers): void {
                unset($activeManagers[$managerId]);
            });

            $manager->handle($connection);
        });

        return static function () use (&$activeManagers): int {
            $count = 0;

            foreach ($activeManagers as $manager) {
                if ($manager->isUpgraded()) {
                    continue;
                }

                $manager->gracefulShutdown();
                $count += $manager->getActiveRequestsCount();
            }

            return $count;
        };
    }
}
