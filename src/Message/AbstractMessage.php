<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Message;

use Hibla\Stream\Interfaces\ReadableStreamInterface;

/**
 * Base abstract class for HTTP messages (Requests and Responses).
 */
abstract class AbstractMessage
{
    /**
     * @var array<string, list<string>> Normalized message headers
     */
    public protected(set) array $headers = [];

    /**
     * @var string The HTTP protocol version
     */
    public protected(set) string $protocolVersion = '1.1';

    /**
     * @var string|ReadableStreamInterface The message body payload.
     *                                     For incoming Requests from the server, this is always a ReadableStreamInterface.
     *                                     For outgoing Responses, this is typically a string, but can be a stream.
     */
    public string|ReadableStreamInterface $body = '';

    /**
     * Retrieves a specific header's values as a list of strings.
     *
     * @return list<string>
     */
    public function getHeader(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    /**
     * Retrieves a specific header as a single, comma-separated string.
     */
    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    /**
     * Checks if a specific header exists on the message.
     */
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /**
     * Sets or overwrites a specific header.
     *
     * @param string $name
     * @param string|array<array-key, string|numeric> $value
     *
     * @return void
     */
    public function setHeader(string $name, string|array $value): void
    {
        $values = [];
        $iterable = \is_array($value) ? $value : [$value];

        foreach ($iterable as $val) {
            $values[] = (string) $val;
        }

        $this->headers[strtolower($name)] = $values;
    }

    /**
     * Appends values to an existing header.
     *
     * @param string $name
     * @param string|array<array-key, string|numeric> $value
     *
     * @return void
     */
    public function addHeader(string $name, string|array $value): void
    {
        $normalizedName = strtolower($name);
        $values = [];
        $iterable = \is_array($value) ? $value : [$value];

        foreach ($iterable as $val) {
            $values[] = (string) $val;
        }

        $this->headers[$normalizedName] = [
            ...($this->headers[$normalizedName] ?? []),
            ...$values,
        ];
    }

    /**
     * Helper to normalize headers arrays on instantiation.
     *
     * @param array<string, string|list<string>> $headers
     *
     * @return array<string, list<string>>
     */
    protected function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = (array) $value;
        }

        return $normalized;
    }
}
