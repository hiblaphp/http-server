<?php

declare(strict_types=1);

use Hibla\HttpServer\ClusterOptions;
use Hibla\HttpServer\HttpServer;
use Hibla\HttpServer\Message\Response;

use function Hibla\await;
use function Hibla\delay;

describe("True Integration Test", function () {
    it('starts the server and gracefully drains in-flight requests on SIGTERM', function () {
        $port = random_int(10000, 15000);
        $address = "127.0.0.1:{$port}";

        $pid = pcntl_fork();
        expect($pid)->not->toBe(-1);

        if ($pid === 0) {
            try {
                HttpServer::create($address)
                    ->withoutLogging()
                    ->withGracefulShutdownTimeout(1.0)
                    ->start(function () {
                        await(delay(0.1));

                        return Response::plaintext('Drained Safely');
                    });
                exit(0);
            } catch (\Throwable $e) {
                fwrite(STDERR, "Child Error: " . $e->getMessage());
                exit(1);
            }
        }

        try {
            usleep(50000);

            $fp = stream_socket_client("tcp://{$address}", $errno, $errstr, 1.0);
            expect($fp)->not->toBeFalse();

            fwrite($fp, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

            usleep(20000);
            posix_kill($pid, SIGTERM);

            $response = '';
            while (!feof($fp)) {
                $response .= fread($fp, 1024);
            }
            fclose($fp);

            expect($response)->toContain('HTTP/1.1 200 OK')
                ->and($response)->toContain('Drained Safely');

            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        } catch (\Throwable $e) {
            posix_kill($pid, SIGKILL);
            throw $e;
        }
    });
});

describe("Clustered Integration Test", function () {
    it('starts the clustered server and gracefully drains in-flight requests on SIGTERM', function () {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Process forking and signal trapping are not supported on Windows.');
        }

        $port = random_int(10000, 15000);
        $address = "127.0.0.1:{$port}";

        $pid = pcntl_fork();
        expect($pid)->not->toBe(-1);

        if ($pid === 0) {
            try {
                HttpServer::create($address)
                    ->withCluster(2, ClusterOptions::make()->withWorkerRestartLimit(10))
                    ->withoutLogging()
                    ->withGracefulShutdownTimeout(1.0)
                    ->start(function () {
                        await(delay(0.1)); 
                        
                        return Response::plaintext('Drained Safely');
                    });
                exit(0);
            } catch (\Throwable $e) {
                fwrite(STDERR, "Master Cluster Error: " . $e->getMessage());
                exit(1);
            }
        }

        try {
            usleep(150000); 

            $fp = stream_socket_client("tcp://{$address}", $errno, $errstr, 1.0);
            expect($fp)->not->toBeFalse();

            fwrite($fp, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

            usleep(20000);
            posix_kill($pid, SIGTERM);

            $response = '';
            while (!feof($fp)) {
                $response .= fread($fp, 1024);
            }
            fclose($fp);

            expect($response)->toContain('HTTP/1.1 200 OK')
                ->and($response)->toContain('Drained Safely');

            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);

        } catch (\Throwable $e) {
            posix_kill($pid, SIGKILL);
            throw $e;
        }
    });
});
