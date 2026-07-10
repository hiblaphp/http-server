<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Internals;

use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\Response;

/**
 * @internal
 *
 * Represents a sequence placeholder for HTTP 1.1 pipelining.
 */
final class Http11PipelineItem
{
    public bool $isReady = false;

    public ?string $earlyResponse = null;

    public bool $earlyResponseSent = false;

    public ?Response $response = null;

    public ?Request $request = null;

    public ?\Closure $disconnectTrigger = null;
}
