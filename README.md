# Hibla HTTP Server

**A high-performance, pristine, async-first HTTP server for PHP built on native PHP Fibers.**

The Hibla HTTP Server is a low-level, strict RFC-compliant protocol engine. It is designed to be the
foundational layer for framework builders, APIs, and microservices. It is intentionally
unopinionated, meaning there is no built-in router, middleware pipeline, or PSR-7 mapping. It simply
gives you raw speed, asynchronous non-blocking I/O, multi-core clustering, and absolute control
over the HTTP request/response lifecycle.

> **The Hibla Ecosystem:** This library is a core component of the broader [Hibla Ecosystem](https://github.com/hiblaphp/hibla). For advanced information on the underlying asynchronous primitives, check out the documentation for the [Socket](https://github.com/hiblaphp/socket), [Stream](https://github.com/hiblaphp/stream), [Promise](https://github.com/hiblaphp/promise), and [Cancellation](https://github.com/hiblaphp/cancellation) libraries.

[![Latest Release](https://img.shields.io/github/release/hiblaphp/http-server.svg?style=flat-square)](https://github.com/hiblaphp/http-server/releases)
[![Tests](https://github.com/hiblaphp/http-server/actions/workflows/test.yml/badge.svg)](https://github.com/hiblaphp/http-server/actions/workflows/test.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/hiblaphp/http-server.svg?style=flat-square)](https://packagist.org/packages/hiblaphp/http-server)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](./LICENSE)

---

## Contents

**Overview**
- [Design Philosophy](#design-philosophy)
- [Production Deployment (Reverse Proxies)](#production-deployment-reverse-proxies)

**Getting Started**
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Streaming-by-Default Architecture](#streaming-by-default-architecture)
- [CRITICAL: The Golden Rule (Never Block)](#critical-the-golden-rule-never-block)
- [The Function Coloring Solution](#the-function-coloring-solution)

**Handling a Request**
- [Asynchronous Execution (Where can I await?)](#asynchronous-execution-where-can-i-await)
- [The Request Object](#the-request-object)
- [Body Parsing (JSON, Buffered)](#body-parsing)
- [The 100-Continue Handshake](#the-100-continue-handshake)
- [Multipart & Upload Handling](#multipart--upload-handling)
  - [Option A: Auto-Buffered Disk Uploads](#option-a-auto-buffered-disk-uploads)
  - [Option B: Zero-Disk Async Streaming](#option-b-zero-disk-async-streaming)
- [The `MultipartForm` Value Object](#the-multipartform-value-object)
- [The Response Object](#the-response-object)
- [Response Factories](#response-factories)
- [Serving Static Files](#serving-static-files)
- [Server-Sent Events (SSE)](#server-sent-events-sse)
- [Protocol Upgrades (WebSockets)](#protocol-upgrades-websockets)
- [Error Handling](#error-handling)
- [Lifecycle Hooks](#lifecycle-hooks)

**Server Configuration**
- [The Fluent Builder](#the-fluent-builder)
- [Security & Limits](#security--limits)
- [Timeouts & Slowloris Protection](#timeouts--slowloris-protection)
- [Graceful Shutdown](#graceful-shutdown)
- [Connection Persistence (Keep-Alive)](#connection-persistence-keep-alive)
- [HTTP/1.1 Pipelining](#http11-pipelining)
- [HTTPS / TLS](#https--tls)
- [Custom Sockets & Testing](#custom-sockets--testing)

**Scaling: Clustered Mode (Multi-core)**
- [How Clustering Works](#how-clustering-works)
- [The Serialization Trap (Dos & Don'ts)](#the-serialization-trap-dos--donts)
- [Cluster Options & IPC](#cluster-options--ipc)

**Hardening & Low-Level Reference**
- [Request Smuggling Defenses](#request-smuggling-defenses)
- [Slowloris & Trickle-Attack Protection](#slowloris--trickle-attack-protection)
- [Upload & Multipart Hardening](#upload--multipart-hardening)
- [Response Header Injection Guard](#response-header-injection-guard)
- [The `ProtocolHandlerInterface`](#the-protocolhandlerinterface)
- [Manual Connection Throttling (Backpressure)](#manual-connection-throttling-backpressure)
- [Hijacking the Connection (Socket Detachment)](#hijacking-the-connection-socket-detachment)

**API Reference**
- [`HttpServerInterface`](#httpserverinterface-api)
- [`ClusterOptions`](#clusteroptions-api)
- [`ProtocolHandlerInterface`](#protocolhandlerinterface-api)
- [`Request` API](#request-api)
- [`Response` API](#response-api)
- [`SseStream` API](#ssestream-api)
- [`MultipartForm` API](#multipartform-api)
- [`UploadedFile` API](#uploadedfile-api)
- [Exceptions Reference](#exceptions-reference)

**Meta**
- [Development](#development)
- [License](#license)

---

# Overview

## Design Philosophy

This library is built like Node.js's `node:http` or Golang's `net/http` module. It operates as a
strictly-scoped protocol engine.

1. **No Routing/Middleware:** Use this library to build your own router or wrap it in a PSR-7
   adapter.
2. **Strict RFC Compliance:** Defends against Request Smuggling (TE.TE/TE.CL), malformed headers,
   and protocol downgrade attacks (RFC 9110/9112/9931 compliant).
3. **Async-First:** Every request handler is executed inside an isolated Fiber. You can `await()`
   database queries, HTTP calls, or `sleep()` without blocking the server from accepting thousands
   of other concurrent connections.

## Production Deployment (Reverse Proxies)

While the HTTP Server is highly secure and capable of binding directly to a public port, **it is highly recommended to run this server behind a reverse proxy** like Nginx, Caddy, or HAProxy in production environments.

Using a reverse proxy provides several architectural advantages:
1. **Privilege Dropping:** It allows your PHP server to run safely on an unprivileged high port (like 8000) while the proxy binds to port 80 and 443 as root.
2. **Protocol Translation (HTTP/2 & HTTP/3):** The HTTP Server natively speaks HTTP/1.1. Native HTTP/2 support is planned for a future release. However, proxies can accept HTTP/2 and HTTP/3 connections from the public internet and effortlessly translate them to HTTP/1.1 for your PHP backend, giving you immediate performance and multiplexing benefits.
3. **SSL/TLS Termination:** Proxies are deeply optimized for SSL handshakes and certificate management.
4. **Static Asset Serving:** Proxies can serve your static CSS, JS, and image files instantly without waking up the PHP runtime.

---

# Getting Started

## Installation

> This package is currently in **beta**. Before installing, ensure your `composer.json` allows
> beta releases.

```bash
composer require hiblaphp/http-server
```

**Requirements:**
- PHP 8.4+ (the library relies on native PHP Fibers and PHP 8.4 property hooks / asymmetric visibility).
- The `pcntl` and `posix` extensions are required to support signal handling and graceful shutdown.
- The `proc_open` function must be enabled in your `php.ini` if you intend to use multi-core clustering.

## Quick Start

```php
use Hibla\HttpServer\HttpServer;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\Response;
use function Hibla\await;

HttpServer::create('127.0.0.1:8000')
    ->withMaxBodySize(10 * 1024 * 1024) // 10MB
    ->onRequest(function (Request $request) {

        if ($request->method === 'POST' && $request->uri === '/api/echo') {
            // Await safely inside the handler; the server runs it in an async Fiber!
            $data = await($request->getJson());
            return Response::json(['you_sent' => $data]);
        }

        return Response::plaintext("Hello from the Server! You requested: {$request->uri}");
    })
    ->start();
```

## Streaming-by-Default Architecture

Unlike traditional PHP setups (such as Nginx + FPM or Apache) which block execution and pre-buffer the
entire request body onto your disk or RAM before your script even starts, **this server is
streaming-by-default.**

The exact millisecond the server finishes parsing the HTTP headers, it fires your `onRequest`
handler. **The request body is still coming over the TCP wire.**

```text
1. Client sends headers ──▶ [Server Parses] ──▶ 2. onRequest() fires immediately!
                                                     │
                                                     ├── (Body is still streaming over TCP)
                                                     │
                                                     └── 3. Your code decides:
                                                            ├── Abort immediately (unauthorized)
                                                            └── Or consume the stream asynchronously
```

Your `Request->body` is an active, unbuffered `RequestBodyStream` which implements the `Hibla\Stream\Interfaces\ReadableStreamInterface`. If you do not read it, the
bytes are never buffered into memory, protecting your server from memory exhaustion attacks. If
your handler returns without ever attaching a listener to a readable body, the connection manager
automatically marks the response `Connection: close` and closes the stream for you. This ensures a client
can't hold the socket open indefinitely with an unconsumed body.

### Convenience Helpers

While the server is strictly streaming under the hood, writing raw chunk-reading loops for every
request is tedious. The `Request` object provides highly optimized, asynchronous
**convenience helpers** that automatically handle the stream buffering and parsing for you
on-demand:

```php
// 1. Buffers the streaming body into a string asynchronously
$rawBodyString = await($request->getBufferedBody());

// 2. Buffers the stream and parses it as a JSON array on-the-fly
$decodedJson = await($request->getJson());

// 3. Streams and parses multipart/form-data asynchronously
$form = await($request->getParsedBody());
```

Using these helpers gives you the simplicity of synchronous-looking code with the memory safety
and speed of a non-blocking streaming engine. Cancelling any of these returned promises mid-stream will instantly and cleanly abort the transfer and free up resources.

## CRITICAL: The Golden Rule (Never Block)

Because the Hibla Event Loop runs on a single thread utilizing cooperative multitasking, **you must never use standard PHP blocking functions.** Furthermore, calling `await()` in the global scope (outside of an `async()` wrapper or a built-in server hook) will cause it to fall back to sequentially blocking the entire thread.

If you block the thread, the entire server halts. The server will completely freeze and will not be able to accept new connections, read TCP data, or resume other Fibers until that blocking function finishes. You must always use the non-blocking async equivalents provided by the Hibla ecosystem.

**THE WRONG WAY (Will freeze the entire server):**
```php
$server->onRequest(function (Request $request) {
    // FATAL: This completely halts the PHP process thread. 
    // No other users can connect or receive data for 2 seconds!
    sleep(2); 
    
    // FATAL: This blocks the thread waiting for network I/O.
    $data = file_get_contents('https://api.example.com/data'); 
    
    // FATAL: Standard PDO blocks the thread while waiting for the database.
    $pdo = new PDO('mysql:host=localhost;dbname=test', 'user', 'pass');
    
    return Response::plaintext($data);
});
```

**THE RIGHT WAY (Non-blocking, cooperative multitasking):**
```php
$server->onRequest(function (Request $request) {
    // SAFE: This suspends only this specific request's Fiber.
    // The server instantly switches to serving other connected users.
    await(\Hibla\delay(2.0)); 
    
    // SAFE: Use the async i/o from the Hibla ecosystem.
    $response = await(\Hibla\HttpClient\Http::get('https://api.example.com/data'));
    $database = await(\Hibla\QueryBuilder\DB::table("users")->get());
    
    return Response::json([
        $response->body(),
       'data' => $database
    ]);
});
```
---

## The Function Coloring Solution

In many asynchronous programming languages, a function is considered "colored" if it internally yields execution. Colored functions must return a wrapper object (such as a Promise) and must be explicitly called using asynchronous keywords.

Because PHP Fibers utilize a **dynamic call stack** rather than compile-time lexical scope, Hibla completely bypasses this limitation.

### Dynamic Call Stack Preservation

The reason this works comes down to one core distinction: PHP Fibers are **stackful coroutines**, unlike the coroutine models in JavaScript, Python, or even PHP's own Generators, all of which are **stackless**.

A stackless coroutine can only suspend from the exact function that was declared as a coroutine (an `async function` in JS/Python, or a `function` containing `yield` in PHP Generators). The moment execution passes into a plain, ordinary helper function that wasn't itself declared that way, that helper has no mechanism to suspend. This is precisely why those languages need function coloring: every function on the path between the entrypoint and the `await` has to be explicitly marked and rewritten to propagate suspension up through the call chain.

A stackful coroutine, by contrast, carries its own independent call stack, the same way an OS thread does. When the HTTP Server starts your `onRequest` handler inside a Fiber, that Fiber's stack grows and shrinks exactly like a normal PHP call stack. The whole stack can be suspended and resumed as one unit from anywhere within it, no matter how many ordinary synchonous function calls deep you are. Nested functions, closures, class constructors, and helper methods don't need to know they're running inside a Fiber, and don't need any special declaration to participate in suspension.

This means Fiber context flows naturally downwards. You can call `await()` deeply nested inside standard PHP functions without wrapping them in `async()` or altering their signatures to return Promises.

```php
use Hibla\HttpServer\HttpServer;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\Response;
use function Hibla\await;
use function Hibla\delay;
use function Hibla\inFiber;

// Deeply nested helper function
function taskInsideNestedCallback(): void {
    // Returns true because the server started the Fiber at the root onRequest handler
    echo inFiber() 
        ? "The deeply nested task is in a Fiber context\n" 
        : "The deeply nested task is not in a Fiber context\n";
}

// Plain, standard PHP function with no "color"
function main(): bool {
    await(delay(0.05));
    taskInsideNestedCallback();
    return inFiber();
}

$server = HttpServer::create('127.0.0.1:8080')
    ->onRequest(function (Request $request) {
        if (main()) {
            echo "The main orchestrator function is in a Fiber\n";
        }
        return Response::plaintext("Hello");
    });
```

Because of this design, you do not have to pollute your code with `async` declarations. You can write clean, synchronous-looking PHP functions using traditional Object-Oriented patterns (like Dependency Injection and Event Listeners). If a deeply nested service needs to hit the database, it simply calls `await()` and the entire call stack safely pauses.

### The Asynchronous Boundary Trap (And How to Bridge It)

While Fibers flow infinitely downward through synchronous code (functions, closures, constructors, and magic methods), **the Fiber context is lost the moment execution crosses an asynchronous boundary.**

When you hand a callback directly to the Event Loop (`Loop::nextTick`, `Loop::addTimer`) or to an asynchronous Promise combinator that takes a callback (`Promise::map`, `Promise::forEach`), that callback is not executed immediately on your current stack. It is stored in memory and executed later by the Event Loop directly on the main thread (`{main}`).

If a callback runs on `{main}` and attempts to call `await()`, it normally triggers a **cooperative blocking fallback** that drives the event loop recursively until the promise settles. Inside a high-concurrency daemon like an HTTP server, this recursive loop re-entrancy wastes CPU cycles and degrades performance.

To protect you from this, the HTTP Server automatically configures `hiblaphp/async` into **Strict Mode** at startup using `AsyncEnvironment::enableStrictAwait()` in top of your script entry point. 

With strict mode active, the "Wrong Way" code below will not silently degrade your performance. Instead, it will instantly throw an `InvalidContextException` pointing directly to the file and line in your code where the context was lost:

#### The Wrong Way:
```php
await(
    Promise::map(["hello", "world"], function ($item) {
        // DANGER: Promise::map executes this closure later on {main}, outside a Fiber!
        // Calling await() here in Strict Mode will instantly throw an InvalidContextException
        // pointing to the UserController.php file.
        await(delay(0.01)); 
        return strtoupper($item);
    })
);
```

#### The Right Way (`asyncFn`):
To bridge this boundary and ensure your callbacks run safely and non-blockingly, you must explicitly spawn a new Fiber for each callback. Hibla provides `async()` and `asyncFn()` helpers exactly for this purpose.

```php
use function Hibla\asyncFn;

await(
    Promise::map(["hello", "world"], asyncFn(function ($item) {
        // SAFE: asyncFn() spawns a new dedicated Fiber for this closure.
        // await() will safely suspend this specific Fiber without blocking the server.
        await(delay(0.01)); 
        return strtoupper($item);
    }))
);
```

### Callsite Concurrency

You only need to introduce the `async()` wrapper at the callsite when you explicitly want to run plain functions concurrently, or when passing closures to async arrays and combinators.

```php
use function Hibla\async;
use function Hibla\await;
use Hibla\Promise\Promise;
use Hibla\QueryBuilder\DB;

function fetchUserData(int $id): array
{
   $userData = await(DB::table("users")->where('id', '=', $id)->get());
}

$server->onRequest(function (Request $request) {
    // Wrap plain functions in async() only when you need to run them concurrently!
    $user1Promise = async(fn() => fetchUserData(42));
    $user2Promise = async(fn() => fetchUserData(99));

    // Resolve both concurrently
    [$user1, $user2] = await(Promise::all([$user1Promise, $user2Promise]));

    return Response::json([$user1, $user2]);
});
```

Using this architecture, your core domain services, helpers, and business logic remain clean, uncolored, and easily testable, only introducing asynchronous promises at the callsite for concurrency control.

---

# Handling a Request

## Asynchronous Execution (Where can I await?)

Because this library is built on native PHP Fibers, several callbacks execute completely implicitly inside their own isolated Fiber. In these contexts, you can freely use `await()` to perform asynchronous non-blocking operations without stalling the main event loop.

You can safely `await()` inside:
1. The `$server->onRequest()` callback.
2. The `$server->onError()` callback.
3. The `$server->onClientDisconnect()` callback (and `$request->onClientDisconnect()`).
4. The `onFile` and `onField` callbacks provided to `$request->streamMultipart()`.
5. The callback provided to `Response::sse()`.
6. The `onUpgrade` callback provided to `Response::upgrade()`.

## The Request Object

The `Request` object represents an incoming HTTP request.

```php
$method = $request->method; // e.g. "POST"
$uri    = $request->uri;    // e.g. "/api/v1/users?active=true"
$version = $request->protocolVersion; // e.g. "1.1"

// Retrieve headers case-insensitively
$token = $request->getHeaderLine('Authorization');
```

## Body Parsing

```php
// Buffers the entire body into memory, rejecting with PayloadTooLargeException
// if it exceeds the server's max body size.
$raw = await($request->getBufferedBody());

// You can also override the global limit on a per-request basis
$largeRaw = await($request->getBufferedBody(maxBytes: 50 * 1024 * 1024));

// Buffers and json_decode()s the body; throws MessageParsingException on invalid JSON.
$data = await($request->getJson());
```

## The 100-Continue Handshake

If a client sends `Expect: 100-continue`, the server automatically writes `HTTP/1.1 100 Continue` back
the moment your handler starts reading the body. This happens the first time something attaches a `data`
listener to `$request->body`, or the first time you `await()` one of the buffered body helpers.
You do not need to detect or respond to the `Expect` header yourself. If you never read the body,
no 100-Continue is ever sent.

## Multipart & Upload Handling

The HTTP Server provides two distinct, highly optimized models for handling
`multipart/form-data` uploads. Both models defend against file-bombing and resource-exhaustion
attacks out of the box.

### Option A: Auto-Buffered Disk Uploads

This is the standard approach. It behaves like traditional PHP uploads but operates **completely
asynchronously** without blocking the thread during disk writes.

Calling `await($request->getParsedBody())` streams the multipart fields and writes file uploads
into secure temporary files on disk (stored in `sys_get_temp_dir()`).

> **An Honest Note on Local Disk I/O (Cooperative File Writing):** At the operating system level, standard local files do not support true non-blocking kernel watchers. When the server writes a file to your local disk, it uses **cooperative time-slicing** (writing the file in micro-chunks and yielding to the Event Loop instantly) to ensure the operation never blocks the server from processing other incoming requests. However, because PHP runs on a single thread, concurrent writes to the same physical disk will execute at roughly the same total speed as sequential writes due to hardware bounds. For massive high-traffic applications, consider **Option B** below to pipe data directly to cloud storage.

```php
use Hibla\HttpServer\Message\MultipartForm;
use Hibla\HttpServer\Message\UploadedFile;

$form = await($request->getParsedBody());

// 1. Get standard form fields
$username = $form->get('username');

// 2. Access uploaded files
$file = $form->getFile('avatar'); // returns ?UploadedFile

if ($file instanceof UploadedFile) {
    echo $file->clientFilename;  // e.g. "profile_pic.jpg" (path-stripped, see hardening notes)
    echo $file->clientMediaType; // e.g. "image/jpeg" (client-supplied, unverified)
    echo $file->size;            // Size in bytes, counted while streaming to disk

    // Move the file asynchronously using pure stream pipes (non-blocking)
    await($file->moveTo('/var/www/uploads/' . $file->clientFilename));
}
```

#### Automatic Garbage Collection

To prevent disk leakages, the HTTP Server cleans up after itself. If an `UploadedFile` object goes out
of scope and is garbage collected without you calling `moveTo()`, the underlying temporary file is
automatically and instantly deleted from your disk.

### Option B: Zero-Disk Async Streaming

This is the ultimate high-performance model. If you are uploading massive files (e.g. 5GB video
files) and need to pipe them directly to S3 or an object storage service, writing them to your
local disk first is a massive waste of I/O.

`streamMultipart()` bypasses the local disk entirely. It parses the incoming TCP stream on-the-fly
and immediately hands you raw, async-readable streams for each file part as it arrives:

```php
use Hibla\Stream\Interfaces\PromiseReadableStreamInterface;

await($request->streamMultipart(
    onFile: function (string $name, string $filename, string $mime, PromiseReadableStreamInterface $fileStream) {
        echo "Streaming file '{$filename}' directly to cloud storage...\n";

        // Read chunks asynchronously directly from the TCP socket as they land!
        // This callback is executed in an isolated Fiber, so await is safe.
        while (($chunk = await($fileStream->readAsync(8192))) !== null) {
            // Write directly to your S3 or Cloud stream
            await($s3UploadStream->writeAsync($chunk));
        }

        echo "Finished streaming {$filename}.\n";
    },
    onField: function (string $name, string $value) {
        // Standard form fields are delivered here as soon as they are parsed
        echo "Received field: {$name} = {$value}\n";
    }
));
```

> **Note:** `streamMultipart()` actively enforces the exact same file and field limits configured via `withMultipartLimits()` to protect your server from resource exhaustion and hash collision attacks, completely mirroring the safety of the buffered `getParsedBody()` approach.

## The `MultipartForm` Value Object

Returned by `getParsedBody()`. Per RFC 7578 §5.2, **fields with duplicate names are never
coalesced**. Every submitted value is retained in submission order. Files sharing the same field
name (multi-file upload) are likewise stored as a list.

```php
$form->get('tags');       // first value only, or null
$form->getAll('tags');    // every value submitted for "tags", in order
$form->getFile('photos'); // first uploaded file for "photos", or null
$form->getFiles('photos');// every uploaded file for "photos", in order
$form->all();             // array<string, list<string>> of every field
```

> **Charset note:** Values are returned as raw, undecoded bytes exactly as received on the wire.
> The parser does not interpret the RFC 7578 §4.5/§4.6 charset hints (per-part `charset` or a
> `_charset_` field). No mainstream browser does either, since modern browsers submit UTF-8
> regardless of page encoding. If you expect a non-UTF-8 charset, decode explicitly with a tool like `mb_convert_encoding()`.

## The Response Object

The `Response` object represents an outgoing HTTP response. It is a lightweight value object.

```php
use Hibla\HttpServer\Message\Response;

// Explicit response creation
$response = new Response(
    statusCode: 200,
    headers: ['Content-Type' => 'text/html'],
    body: '<h1>Welcome</h1>'
);
```

## Response Factories

For standard workloads, always use the highly optimized factory methods:

```php
return Response::plaintext('Hello');
return Response::json(['status' => 'created'], 201); // pretty-printed, unescaped slashes/unicode
return Response::html('<h1>Not Found</h1>', 404);
return Response::redirect('/login', 302);
```

If `$response->body` is set to any `Hibla\Stream\Interfaces\ReadableStreamInterface` instead of a string, the server automatically streams it out using chunked transfer encoding unless you have explicitly set a `Content-Length` header yourself.

## Serving Static Files

The server provides a highly optimized, asynchronous static file serving factory. It automatically:
- Detects the file's `Content-Type` safely from its extension.
- Injects precise `Content-Length` headers.
- Parses the request's `Range` header to serve **HTTP 206 Partial Content**. This allows browsers
  and mobile clients to scrub, seek, and buffer video or audio files perfectly with zero lag.
- Returns a `404` `Response::plaintext` automatically if the file doesn't exist or isn't readable, so you don't need to check beforehand.

> **An Honest Note on Static File Serving:** Just like local file uploads, serving static files directly from PHP relies on cooperative time-slicing. While this protects the event loop from stalling, reading hundreds of files concurrently on a single thread is bottlenecked by your server's local disk I/O. **For production, it is strongly recommended to let your reverse proxy (Nginx/Caddy), a CDN, or an Object Storage service (S3) serve static files** rather than tying up your PHP workers.

```php
return Response::file('/var/www/public/video.mp4', $request);
```

## Server-Sent Events (SSE)

Real-time streaming is natively supported. The SSE factory automatically configures
non-buffering headers (`Cache-Control: no-cache`, `X-Accel-Buffering: no`), keep-alive parameters,
and manages the background execution loop inside an isolated Fiber.

The `SseStream` provided to your callback implements `Hibla\Stream\Interfaces\ReadableStreamInterface`, making it fully compatible with the underlying stream engine.

```php
use Hibla\HttpServer\Message\SseStream;

return Response::sse(function (SseStream $stream) {
    while ($stream->isReadable()) {
        $data = json_encode(['metrics' => getSystemMetrics()]);

        // Push the formatted SSE frame onto the wire
        $stream->send($data, event: 'metrics_update');

        // Sleep for 1 second asynchronously without blocking the event loop!
        await(\Hibla\delay(1.0));
    }
});
```

Calling `$stream->ping($comment = 'ping')` emits a spec-compliant comment line (`: ping\n\n`) to keep
proxies and load balancers from timing out an idle connection. Both `send()` and `ping()`
transparently suspend the emitter Fiber under TCP backpressure and resume once the client drains.

## Protocol Upgrades (WebSockets)

For protocols like WebSockets or HTTP CONNECT tunneling, you can hijack the raw TCP connection
entirely. Once you call `upgrade()`, the server detaches the socket from the HTTP loop and hands
it directly to your callback:

```php
return Response::upgrade(
    status: 101,
    headers: ['Upgrade' => 'websocket', 'Connection' => 'Upgrade'],
    onUpgrade: function ($connection, string $trailingBytes) {
        // You now own the raw Hibla\Socket\Interfaces\ConnectionInterface socket!
        $connection->write("Switched to WebSocket protocol.\n");

        $connection->on('data', function ($chunk) use ($connection) {
            $connection->write("Echo: " . $chunk);
        });
    }
);
```

## Error Handling

### Exception Hierarchy

All exceptions extend `Hibla\HttpServer\Exceptions\HttpServerException` (which is a `RuntimeException`),
so you can catch broadly or narrowly depending on your needs.

```text
HttpServerException
├── InvalidConfigurationException     // e.g. start() called with no onRequest handler
├── InvalidResponseException          // bad Response from handler or error-handler, or CRLF/NUL in a header value
├── JsonEncodingException             // Response::json() given data json_encode can't serialize
├── MessageParsingException           // malformed request line / headers (→ 400)
│   ├── RequestHeaderFieldsTooLargeException  // too many header fields (→ 431)
│   └── UnsupportedTransferCodingException    // unrecognized Transfer-Encoding coding (→ 501)
├── MultipartException                // generic multipart parser error
│   ├── MalformedMultipartException   // missing/invalid boundary
│   └── MultipartPartTooLargeException// a part's headers exceed the configured limit
├── PayloadTooLargeException          // body exceeds withMaxBodySize() (→ 413)
├── FileAlreadyMovedException         // UploadedFile::moveTo() called twice
├── UploadedFileNotFoundException     // UploadedFile::moveTo() but the temp file is gone
└── (Stream-related, thrown from underlying stream operations)
    ├── StreamClosedException
    ├── StreamNotWritableException
    └── StreamTransferException
```

These map directly onto the HTTP status codes noted above when they escape unhandled from
protocol parsing. You generally only need to catch them yourself around the **application-level**
helper calls (`getJson()`, `getParsedBody()`, `UploadedFile::moveTo()`, etc.):

```php
use Hibla\HttpServer\Exceptions\PayloadTooLargeException;
use Hibla\HttpServer\Exceptions\MessageParsingException;

try {
    $data = await($request->getJson());
} catch (PayloadTooLargeException $e) {
    return Response::plaintext('Body too large', 413);
} catch (MessageParsingException $e) {
    return Response::plaintext('Invalid JSON', 400);
}
```

### `onError` Behavior

Uncaught exceptions thrown inside your `onRequest` handler are routed to your `onError` callback
(if registered). Otherwise, a generic `500 Internal Server Error` plaintext body is sent.

Because an uncaught exception means internal state or streams may be unstable, **any response
returned from `onError` automatically gets `Connection: close` appended** unless you already set
it. The connection is not reused for a following request, even if you do not ask for that
explicitly. If your `onError` callback itself throws an exception, or returns something other than a `Response`
or null, the server falls back to the generic 500 response rather than propagating the failure.

## Lifecycle Hooks

### `onRequest`
The primary hook. Called every time a complete HTTP header block is parsed. Executed safely in its own dedicated Fiber.

### `onError`
Catch unhandled exceptions thrown by your `onRequest` callback and format a proper response. See
[`onError` Behavior](#onerror-behavior) above for the automatic `Connection: close` caveat.

```php
$server->onError(function (\Throwable $e, Request $request) {
    return Response::json(['error' => $e->getMessage()], 500);
});
```

### `onClientDisconnect` & Cancellation Tokens
Triggered if the client drops the TCP connection before your `onRequest` handler has finished
responding.

Because the ecosystem is built heavily around structured concurrency, you can combine the `onClientDisconnect` event with a `CancellationTokenSource` to gracefully cancel long-running promises. This saves CPU cycles and database connections if a user abandons their request early.

```php
use Hibla\Cancellation\CancellationTokenSource;
use Hibla\Promise\Exceptions\CancelledException;

$server->onRequest(function (Request $request) {
    // 1. Create a Cancellation Token Source
    $cts = new CancellationTokenSource();

    // 2. If the user closes their browser tab, trigger the cancellation!
    $request->onClientDisconnect(function () use ($cts) {
        $cts->cancel();
    });

    try {
        // 3. Pass the token down to your async tasks. 
        // If $cts->cancel() fires, the fetch operation instantly aborts.
        $data = await(fetchAnalyticsDataAsync($cts->token));
        return Response::json($data);
        
    } catch (CancelledException $e) {
        // The promise was cancelled cleanly.
        // Returning null instructs the server not to attempt sending a response.
        return null;
    }
});
```

### `onStart`
Fired exactly once, right before the server binds to the socket and starts accepting connections.
In clustered mode, this fires inside each isolated worker subprocess. See the
[Serialization Trap](#the-serialization-trap-dos--donts) below for why this matters.

---

# Server Configuration

## The Fluent Builder

`HttpServer::create()` returns an immutable fluent builder. Every configuration method returns a
**new cloned instance**, so you can safely chain configuration methods to build your ideal server
state before calling `->start()`.

## Security & Limits

Protect your server from memory exhaustion and abuse:

```php
HttpServer::create()
    ->withMaxConnections(limit: 10000, pauseOnLimit: true) // Backpressure control
    ->withMaxBodySize(15 * 1024 * 1024)                    // 15MB max request body
    ->withHeaderLimits(maxSize: 8192, maxCount: 100)       // Prevent header bloat
    ->withMultipartLimits(maxFiles: 20, maxFields: 1000)   // Prevent hash collisions
```

The `pauseOnLimit` flag in `withMaxConnections` controls what happens once the connection cap is hit. Passing `true` applies backpressure at the OS level so new connections wait, while `false` accepts and
immediately drops connections past the limit.

## Timeouts & Slowloris Protection

Drop connections that are intentionally trickling data to tie up your sockets. All of these timeout features are disabled (`null`) by default to accommodate long-running streams, unless explicitly configured:

```php
HttpServer::create()
    // Drop if headers take > 5s (Default: null or disabled)
    ->withHeaderTimeout(5.0)   
    
    // Drop if 10s pass between body chunks (Default: null or disabled)
    ->withBodyTimeout(10.0)    
    
    // Hard 60s absolute limit for the whole request (Default: null or disabled)
    ->withRequestTimeout(60.0) 
```

## Graceful Shutdown

When the server receives a termination signal (`SIGINT` or `SIGTERM`) during deployments or scaling events, it does not abruptly sever active user connections. Instead, the server stops accepting new connections and waits for all currently executing requests to finish cleanly.

You can configure the absolute maximum time the server will wait for active requests to finish before it forcefully exits:

```php
HttpServer::create()
    // Max time to drain requests on SIGTERM (Default: 15.0 seconds)
    ->withGracefulShutdownTimeout(15.0) 
```

## Connection Persistence (Keep-Alive)

```php
HttpServer::create()
    // Close idle connections after 5s (Default: null or disabled)
    ->withKeepAliveTimeout(5.0)       
    
    // Force reconnect after 100 requests (Default: null or unlimited)
    ->withKeepAliveMaxRequests(100)   
    
    // Set the HTTP/1.1 Pipelining depth queue (Default: 128)
    ->withMaxConcurrentRequestsPerConnection(128) 
```

> **Important Proxy Configuration Note:**
> If you are running this HTTP Server behind a reverse proxy like Nginx or HAProxy, you must ensure that your `withKeepAliveTimeout()` value is **strictly greater** than the proxy's configured keep-alive timeout.
> 
> If the PHP server closes the connection before the proxy expects it to, the proxy will encounter a closed socket when attempting to forward the next user's request. This race condition results in intermittent `502 Bad Gateway` errors in production. Let the proxy manage the idle timeouts, and set the PHP server timeout slightly higher as a safety net.

## HTTP/1.1 Pipelining

The HTTP Server supports HTTP/1.1 request pipelining out of the box. Multiple requests can arrive on the
same connection before earlier ones have finished, and each is processed concurrently in its own
Fiber. Responses are flushed back to the client **strictly in the order the requests were
received** per RFC 9112. The server queues completed responses internally and only writes them once
every earlier response in the pipeline is ready. Calling `withMaxConcurrentRequestsPerConnection()`
bounds how deep that queue is allowed to grow per connection before the socket is paused.

## HTTPS / TLS

```php
HttpServer::create('0.0.0.0:443')
    ->withTls([
        'local_cert' => '/path/to/cert.pem',
        'local_pk'   => '/path/to/key.pem',
    ])
```

TLS metadata (negotiated protocol, cipher, and client certificate subject if mutual TLS is used)
is exposed on `$request->serverParams` as `SSL_PROTOCOL`, `SSL_CIPHER`, and
`SSL_CLIENT_CERT_SUBJECT`.

## Custom Sockets & Testing

For unit/integration testing, or to plug in an already-configured transport, you can inject your own
socket server directly. This bypasses `withCluster()`, single-process binding, and TLS
configuration, giving you complete ownership of the socket lifecycle:

```php
use Hibla\Socket\SocketServer;

$socket = new SocketServer('127.0.0.1:0'); // ephemeral port, useful in tests

HttpServer::create()
    ->withSocketServer($socket)
    ->onRequest(fn ($req) => Response::plaintext('ok'))
    ->start();
```

Calling `withContext(array $context)` merges recursively into any existing context on each call
(`array_merge_recursive`), so successive calls accumulate rather than replace. This is useful for
composing TCP-level options (e.g. `so_reuseport`) alongside TLS options.

---

# Scaling: Clustered Mode (Multi-core)

Node.js and traditional PHP share a limitation in that an event loop only runs on a single CPU core.
The HTTP Server solves this natively using `SO_REUSEPORT` and `hiblaphp/parallel`.

You can fork multiple worker processes that natively share the TCP port load, maximizing CPU
utilization:

```php
// Spawns 8 independent worker processes handling requests simultaneously
HttpServer::create('0.0.0.0:8000')
    ->withCluster(8)
    ->onRequest(...)
    ->start();
```

> Clustering is **not supported on Windows** because there is no `SO_REUSEPORT` functionality. The HTTP Server detects this automatically
> and falls back to single-process mode with a warning, rather than failing to start.

## How Clustering Works

When you call `withCluster(8)`, the Master process serializes your `onRequest`, `onError`, and
`onClientDisconnect` closures, sends them over IPC pipes, and forks 8 child workers. The children
deserialize those closures and run the actual HTTP servers.

## The Serialization Trap (Dos & Don'ts)

Because your closures are serialized and sent across process boundaries, **they cannot capture
active OS resources** like open PDO database connections, File handles, or Redis clients from
the Master process.

**THE WRONG WAY (Will crash in Cluster Mode):**
```php
// Executed in the MASTER PROCESS
$logger = new FileLogger('/var/log/app.log');

HttpServer::create('127.0.0.1:8000')
    ->withCluster(4)
    ->onError(function (\Throwable $e, Request $request) use ($logger) {
        // ERROR: The Parallel library cannot serialize the $logger file resource
        // to send it to the child workers. This will throw a TaskPayloadException.
        $logger->log($e->getMessage());
    })
    ->start();
```

**THE RIGHT WAY (Using `onStart`):**
To make this work cleanly, initialize your connections *inside the worker process* using the
`onStart` hook.

```php
HttpServer::create('127.0.0.1:8000')
    ->withCluster(4)
    ->onStart(function () {
        // This runs INSIDE the child worker right after it boots.
        // It is perfectly safe to open Database/File resources here.
        global $workerLogger;
        $workerLogger = new FileLogger('/var/log/app.log');
    })
    ->onError(function (\Throwable $e, Request $request) {
        // Safe to serialize: captures no external scope
        global $workerLogger;
        $workerLogger->log($e->getMessage());
        return Response::plaintext('Internal Error', 500);
    })
    ->start();
```

*Note: Framework builders can also use Dependency Injection containers or Static Facades
initialized via `ClusterOptions::withClusterBootstrap()`, as static calls do not capture scope.*

## Cluster Options & IPC

Pass a `ClusterOptions` object to configure worker environments and Inter-Process Communication
(IPC):

```php
use Hibla\HttpServer\ClusterOptions;

$options = ClusterOptions::make()
    ->withWorkerMemoryLimit('256M')
    ->withWorkerRestartLimit(10) // Prevent fork-bomb crash loops
    ->withClusterBootstrap('/path/to/autoload.php') // Preload legacy code
    ->onWorkerMessage(function ($message) {
        // Receive messages emitted by workers to the Master process
        echo "Worker {$message->pid} says: {$message->data}\n";
    });

HttpServer::create()
    ->withCluster(4, $options)
    ->onRequest(function(Request $request) {
        \Hibla\emit('I just served a request!'); // Send message to Master via IPC
        return Response::plaintext('OK');
    })
    ->start();
```

If a worker dies unexpectedly, the Master automatically respawns a replacement worker (bounded by
`withWorkerRestartLimit()` to avoid crash-loop fork bombs) and logs the active PID list.

---

# Hardening & Low-Level Reference

These protections are always active, and there is nothing to opt into.

## Request Smuggling Defenses

The protocol parser enforces RFC 9112 framing rules that specifically close known
request-smuggling vectors:

- Rejects requests carrying **both** `Content-Length` and `Transfer-Encoding` (CL.TE/TE.CL).
- Rejects a `chunked` coding that appears anywhere **except last** in a `Transfer-Encoding` chain
  (TE.TE).
- Rejects duplicate or conflicting `Content-Length` values, including comma-separated lists.
- Rejects malformed `Content-Length` values (e.g. `"10abc"`, `"-5"`) instead of silently coercing
  them with a numeric cast.
- Forces connection closure whenever an HTTP/1.0 request carries `Transfer-Encoding` at all, since
  no compliant 1.0 sender should ever produce one.
- Enforces the HTTP `token` grammar on both the request method and every header field name,
  rejecting control characters and delimiter characters that a lenient upstream proxy might
  normalize differently.
- Rejects obsolete line folding (`obs-fold`) and bare `CR` characters not followed by `LF`
  anywhere in the request line or header block.
- A rejected `CONNECT` request (4xx/5xx) forces the connection closed immediately after the
  response, ensuring optimistically-pipelined bytes can never be misread as a new request.

## Slowloris & Trickle-Attack Protection

- `headerTimeout` bounds how long a client may take to finish sending headers.
- `bodyTimeout` bounds inactivity between body chunks, protecting long uploads from stalling
  attackers without punishing genuinely slow connections.
- `requestTimeout` bounds the entire request (headers + body) end-to-end.
- Header block size is capped (`maxHeaderSize`, default 16 KiB) even before the terminating
  `\r\n\r\n` is seen. This prevents memory exhaustion from clients that never finish their headers.
- Chunk-size lines in chunked transfer encoding are capped at 1 KiB to prevent unbounded buffer
  growth from a chunk-size line with no terminating CRLF.

## Upload & Multipart Hardening

- Multipart parts are structurally validated against RFC 7578 §4.2. Parts without
  `Content-Disposition: form-data` are parsed to keep boundary tracking correct, but they are never
  surfaced as a field or file.
- **Client-supplied filenames are sanitized to a bare leaf name** before being exposed as
  `UploadedFile::$clientFilename`. Both POSIX (`../../etc/evil.txt`, `/etc/passwd`) and
  Windows-style (`C:\evil.txt`) path separators are stripped. Never trust this value as a
  storage path regardless.
- `withMultipartLimits()` caps both file count and field count per request to prevent
  file-bombing and hash-collision attacks against your form-processing code.
- Individual multipart header blocks are capped independently of the outer HTTP header limit.

## Response Header Injection Guard

If your application code sets a response header value containing a raw `\r`, `\n`, or `\0`, the HTTP Server
throws an `InvalidResponseException` rather than writing it to the wire. This prevents HTTP
response splitting from user-controlled header values and surfaces the bug to you immediately
instead of silently corrupting the response stream.

## The `ProtocolHandlerInterface`

For framework builders, the HTTP Server exposes the extreme low-level HTTP transport layer. Your
`onRequest()` callback actually receives a second argument which is the `ProtocolHandlerInterface`.

This interface is the actual state machine driving the raw TCP socket. Accessing it directly lets
you bypass the standard request-response loop for advanced networking, debugging, or custom
protocols.

```php
use Hibla\HttpServer\Interfaces\ProtocolHandlerInterface;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\Response;

$server->onRequest(function (Request $request, ProtocolHandlerInterface $protocol) {
    // 1. Inspect the underlying socket connection directly
    $socket = $protocol->connection;
    $remoteIp = $socket->getRemoteAddress();

    // 2. Check active pipeline concurrency on this specific socket
    $concurrentRequestsOnSocket = $protocol->activeRequestsCount;

    // 3. Write responses with explicit event callbacks
    $protocol->writeResponse(Response::plaintext('Direct Write'), function () {
        // This callback is executed the exact millisecond the bytes leave the OS buffer!
        echo "Response fully transmitted to client.\n";
    });
});
```

## Manual Connection Throttling (Backpressure)

One of the major benefits of accessing the raw `$protocol->connection` is the ability to apply
**manual backpressure** (TCP flow control). If a client is uploading a massive file or flooding
the server with requests faster than your database or downstream consumers can process them, you
can tell the OS kernel to stop reading packets from the TCP socket by calling `pause()`. Once your
queue clears, call `resume()` to start reading again:

```php
$server->onRequest(function (Request $request, ProtocolHandlerInterface $protocol) {
    $socket = $protocol->connection;

    // Stop reading any new TCP packets from this client!
    // The client's OS will buffer data locally (TCP Window saturation)
    $socket->pause();

    Hibla\async(function () use ($socket, $request) {
        // Process a slow, database i/o task asynchronously
        await(slowDatabaseWrite(await($request->getBufferedBody())));

        // We are ready for more data. Tell the kernel to resume reading!
        $socket->resume();
    });

    return Response::plaintext('Processed');
});
```

## Hijacking the Connection (Socket Detachment)

If you need to transition the socket into an entirely different protocol (such as WebSockets, SSH
tunnelling, or custom binary framing), you can explicitly **detach** the protocol handler.

Detaching stops all HTTP parsing, cancels any associated HTTP timeouts, and returns any unparsed
bytes currently sitting in the receive buffer:

```php
$server->onRequest(function (Request $request, ProtocolHandlerInterface $protocol) {
    if ($request->getHeaderLine('Upgrade') === 'my-custom-protocol') {

        // Send the HTTP protocol switch response
        $protocol->writeResponse(new Response(101, [
            'Upgrade' => 'my-custom-protocol',
            'Connection' => 'Upgrade'
        ]));

        // Hijack and detach!
        $rawSocket = $protocol->connection;
        $unparsedBytes = $protocol->detach(); // Cleans up and returns trailing bytes

        // The HTTP server has completely forgotten about this socket.
        // You are now writing raw TCP data:
        if ($unparsedBytes !== '') {
            processCustomFraming($unparsedBytes);
        }

        $rawSocket->on('data', function (string $chunk) use ($rawSocket) {
            $rawSocket->write("Echo: " . $chunk);
        });

        return; // Return null so the server knows not to try to send a response
    }

    return Response::plaintext('Standard HTTP');
});
```

---

# API Reference

## `HttpServerInterface` API

All fluent configuration methods return a new cloned instance of the server.

| Method | Return Type | Description |
|:---|:---|:---|
| `HttpServer::create(string\|int $address)` | `HttpServerInterface` | Named constructor. Spawns a new server instance. Accepts a port (`8080`) or full URI (`127.0.0.1:8000`). |
| `withSocketServer(ServerInterface $socket)` | `static` | Inject a custom pre-bound socket server instance (useful for testing). Disables clustering. |
| `withContext(array $context)` | `static` | Configure raw stream/socket context options. Merges recursively across calls. |
| `withTls(array $tlsOptions)` | `static` | Configure SSL/TLS parameters. |
| `withCluster(int $workers, ?ClusterOptions $opts)` | `static` | Enable multi-process worker clustering via `SO_REUSEPORT` (no-op on Windows). |
| `withoutCluster()` | `static` | Run entirely inside the parent thread (disables clustering). |
| `withoutLogging()` | `static` | Disable built-in CLI starting logs. |
| `withMaxBodySize(int $bytes)` | `static` | Maximum allowed request body size (Default: 10MB). |
| `withMaxConnections(int $limit, bool $pause)` | `static` | Set concurrency backpressure bounds. |
| `withHeaderLimits(int $size, int $count)` | `static` | Maximum allowed header block size and count limits. |
| `withHeaderTimeout(?float $seconds)` | `static` | Slowloris protection timeout for request headers. |
| `withBodyTimeout(?float $seconds)` | `static` | Timeout between receiving active request body chunks. |
| `withRequestTimeout(?float $seconds)` | `static` | Absolute timeout allowed for a complete request (Headers + Body). |
| `withKeepAliveTimeout(?float $seconds)` | `static` | Max idle duration allowed for persistent connections. |
| `withKeepAliveMaxRequests(?int $limit)` | `static` | Max requests allowed over a single connection. |
| `withGracefulShutdownTimeout(float $sec)` | `static` | Max time allowed to drain in-flight requests on SIGTERM. |
| `withMaxConcurrentRequestsPerConnection(int $n)`| `static` | Limit pipelining queue depth per TCP socket. |
| `withMultipartLimits(int $files, int $fields)` | `static` | Limit files and fields inside `multipart/form-data`. |
| `onRequest(callable $handler)` | `static` | Register the primary async HTTP request handler. |
| `onError(callable $handler)` | `static` | Register a global error interceptor callback. |
| `onClientDisconnect(callable $callback)` | `static` | Register a callback for aborted requests. |
| `onStart(callable $callback)` | `static` | Register a late-stage worker initialization callback. |
| `start()` | `void` | Binds the sockets and starts the Event Loop (blocking). Throws `InvalidConfigurationException` if no handler is set. |

## `ClusterOptions` API

| Method | Return Type | Description |
|:---|:---|:---|
| `ClusterOptions::make()` | `ClusterOptions` | Named constructor for fluent chain building. |
| `withWorkerMemoryLimit(string $limit)` | `static` | Set memory limits (ini_set) inside spawned workers. |
| `withWorkerRestartLimit(?int $limit)` | `static` | Max worker restarts allowed per second (Default: 10). |
| `withClusterBootstrap(string $file, ?callable $cb)`| `static` | Specify a bootstrap file to run before workers boot. |
| `onWorkerMessage(callable $handler)` | `static` | Register an IPC handler to receive worker `emit()` payloads. |

## `ProtocolHandlerInterface` API

Exposed as the second argument to `onRequest()`. Controls raw TCP transport-to-HTTP mapping.

| Property / Method | Return Type / Signature | Description |
|:---|:---|:---|
| `$connection` | `ConnectionInterface` | **Property (Read-only)**. The underlying raw socket. |
| `$activeRequestsCount` | `int` | **Property (Read-only)**. Active concurrent requests on this socket. |
| `isUpgraded()` | `bool` | Checks if the connection has been hijacked/upgraded. |
| `writeResponse` | `(Response $response, ?callable $onComplete = null): void` | Serializes and writes a Response directly onto the wire. |
| `detach()` | `(): string` | Halts HTTP parsing and releases the raw socket. Returns unparsed buffer bytes. |
| `gracefulShutdown()` | `(): void` | Signals the handler to cleanly finish and disconnect. |

## `Request` API

The value object representing an incoming HTTP Request.

| Property / Method | Return Type / Signature | Description |
|:---|:---|:---|
| `$method` | `string` | **Property (Read-only)**. The HTTP method (e.g. `"POST"`). |
| `$uri` | `string` | **Property (Read-only)**. Raw target URI path and query string. |
| `$protocolVersion`| `string` | **Property (Read-only)**. Protocol version (e.g. `"1.1"`). |
| `$serverParams` | `array<string, string>` | **Property (Read-only)**. Environment parameters (`REMOTE_ADDR`, `REMOTE_PORT`, `SERVER_ADDR`, `SERVER_PORT`, `REQUEST_SCHEME`, `HTTPS`, `SSL_PROTOCOL`, `SSL_CIPHER`, `SSL_CLIENT_CERT_SUBJECT`). |
| `$body` | `RequestBodyStream` | **Property (Read-write)**. The unbuffered body stream. Can be manually reassigned or piped. |
| `hasHeader` | `(string $name): bool` | Check if a specific header exists (case-insensitively). |
| `getHeader` | `(string $name): string[]`| Get list of values for a specific header name. |
| `getHeaderLine` | `(string $name): string` | Get header values flattened into a comma-separated string. |
| `getBufferedBody` | `(?int $maxBytes = null): PromiseInterface<string>` | Asynchronously buffer the entire request body into a string. Throws `PayloadTooLargeException` if exceeded. |
| `getJson` | `(?int $maxBytes = null): PromiseInterface<mixed>` | Asynchronously buffer and decode the body as JSON. Throws `MessageParsingException` on invalid JSON. |
| `getParsedBody` | `(): PromiseInterface<MultipartForm>` | Asynchronously parse multipart data, buffering files to temp disk. |
| `streamMultipart` | `(callable $onFile, ?callable $onField): PromiseInterface<void>` | Stream multipart on-the-fly directly to memory (zero-disk). |
| `onClientDisconnect`| `(callable $callback): static` | Register a callback to fire if the TCP socket drops early. |
| `isDisconnected` | `(): bool` | Checks if the client has already disconnected. |

## `Response` API

The value object representing an outgoing HTTP Response.

| Factory / Method | Return Type / Signature | Description |
|:---|:---|:---|
| `Response::plaintext` | `(string $text, int $status = 200): Response` | **Factory**. Create a plaintext response. |
| `Response::json` | `(mixed $data, int $status = 200): Response` | **Factory**. Create a JSON response. Throws `JsonEncodingException` if the data can't be encoded. |
| `Response::html` | `(string $html, int $status = 200): Response` | **Factory**. Create an HTML response. |
| `Response::redirect` | `(string $url, int $status = 302): Response` | **Factory**. Create a redirect response. |
| `Response::file` | `(string $path, ?Request $req = null, array $headers = []): Response` | **Factory**. Async, stream-based file serve with HTTP 206 support. Returns a 404 automatically if unreadable. |
| `Response::sse` | `(callable $emitter): Response` | **Factory**. Ergonomic Server-Sent Events stream. |
| `Response::upgrade` | `(int $status, array $hdr, callable $onUpgrade): Response` | **Factory**. Hijack the TCP socket for custom protocols (WebSockets). |
| `hasHeader` | `(string $name): bool` | Checks if a header exists. |
| `getHeader` | `(string $name): string[]`| Get values of a specific header. |
| `getHeaderLine` | `(string $name): string` | Get comma-separated string of header values. |
| `setHeader` | `(string $name, string\|array $val): void` | Overwrite or set a header. Throws `InvalidResponseException` on CR/LF/NUL in the value. |
| `addHeader` | `(string $name, string\|array $val): void` | Append values onto an existing header. |

## `SseStream` API

Passed to the callback of `Response::sse()`. Implements `Hibla\Stream\Interfaces\ReadableStreamInterface`.

| Method | Return Type / Signature | Description |
|:---|:---|:---|
| `send` | `(string $data, ?string $event = null, ?string $id = null, ?int $retry = null): void` | Safely formats and pushes an SSE message to the client. Suspends under backpressure. |
| `ping` | `(?string $comment = 'ping'): void` | Emits a standard-compliant SSE comment block to keep the connection alive. |
| `isReadable` | `(): bool` | Checks if the stream is still open and readable. |
| `close` | `(): void` | Forcefully closes the SSE stream. |
| `end` | `(): void` | Ends the stream gracefully. |

## `MultipartForm` API

Returned by `Request::getParsedBody()`.

| Method | Return Type / Signature | Description |
|:---|:---|:---|
| `get` | `(string $name): ?string` | First value submitted for the field, or `null`. |
| `getAll` | `(string $name): list<string>` | Every value submitted for the field, in order. |
| `getFile` | `(string $name): ?UploadedFile` | First uploaded file for the field, or `null`. |
| `getFiles` | `(string $name): list<UploadedFile>` | Every uploaded file for the field, in order (handles both `name` and `name[]`). |
| `all` | `(): array<string, list<string>>` | Every field and its submitted values. |

## `UploadedFile` API

Represents a parsed file upload buffered asynchronously to your temp disk.

| Property / Method | Return Type / Signature | Description |
|:---|:---|:---|
| `$tmpPath` | `string` | **Property (Read-only)**. Temporary storage path on the local disk. |
| `$clientFilename` | `string` | **Property (Read-only)**. Client-supplied file name, directory path stripped (see hardening notes). |
| `$clientMediaType`| `string` | **Property (Read-only)**. Client-supplied MIME type. This is unverified and spoofable, so use content-based detection if it matters. |
| `$size` | `int` | **Property (Read-only)**. Total size of the uploaded file in bytes. |
| `moveTo` | `(string $destinationPath): PromiseInterface<void>` | Asynchronously move the temporary file to its final target path. Throws `FileAlreadyMovedException` or `UploadedFileNotFoundException`. |

## Exceptions Reference

All under `Hibla\HttpServer\Exceptions\`, all extend `HttpServerException` (a `RuntimeException`).

| Exception | Typically thrown when | Resulting status (if unhandled) |
|:---|:---|:---:|
| `InvalidConfigurationException` | `start()` called with no request handler; invalid limit values passed to builder methods |  |
| `InvalidResponseException` | Handler returns a non-`Response`; header value contains CR/LF/NUL | 500 |
| `JsonEncodingException` | `Response::json()` given data `json_encode` can't serialize | 500 |
| `MessageParsingException` | Malformed request line, headers, or Content-Length | 400 |
| `RequestHeaderFieldsTooLargeException` | Too many header fields, or a field name too long | 431 |
| `UnsupportedTransferCodingException` | Unrecognized `Transfer-Encoding` coding | 501 |
| `MultipartException` | Generic multipart stream/parser failure, or too many files/fields |  |
| `MalformedMultipartException` | Missing or invalid multipart boundary |  |
| `MultipartPartTooLargeException` | A multipart part's headers exceed the configured limit |  |
| `PayloadTooLargeException` | Body exceeds `withMaxBodySize()` | 413 |
| `FileAlreadyMovedException` | `UploadedFile::moveTo()` called a second time |  |
| `UploadedFileNotFoundException` | `UploadedFile::moveTo()` called after the temp file is gone |  |
| `StreamClosedException` | Read/write attempted on an already-closed stream |  |
| `StreamNotWritableException` | Write attempted on a non-writable destination during a pipe |  |
| `StreamTransferException` | Destination closed before a stream transfer completed |  |

---

# Meta

## Development

If you would like to contribute to the HTTP Server or run the test suite locally, you can clone the repository and use Composer to install the development dependencies:

```bash
git clone https://github.com/hiblaphp/http-server.git
cd http-server
composer install
```

**Running Tests**
The project uses Pest for testing.
```bash
./vendor/bin/pest
```

**Static Analysis**
The project strictly enforces PHPStan at the maximum level.
```bash
./vendor/bin/phpstan analyse
```

**Code Formatting**
The codebase follows PSR-12 standards enforced by Laravel Pint.
```bash
./vendor/bin/pint
```

## License

MIT License. See [LICENSE](./LICENSE) for more information.