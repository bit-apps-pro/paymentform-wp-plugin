<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Support;

use ArrayAccess;

/**
 * Mock WP_REST_Request for unit testing controllers without WordPress runtime.
 */
final class MockWpRestRequest implements \ArrayAccess
{
    private string $method;
    private string $path;
    private array $params = [];
    private string $body = '';
    private array $headers = [];

    public function __construct(string $method, string $path, array $params = [], string $body = '', array $headers = [])
    {
        $this->method = $method;
        $this->path = $path;
        $this->params = $params;
        $this->body = $body;
        $this->headers = $headers;
    }

    public function get_method(): string
    {
        return $this->method;
    }

    public function get_route(): string
    {
        return $this->path;
    }

    public function get_param(string $key): mixed
    {
        return $this->params[$key] ?? null;
    }

    public function get_params(): array
    {
        return $this->params;
    }

    public function get_body(): string
    {
        return $this->body;
    }

    public function set_body(string $body): void
    {
        $this->body = $body;
    }

    public function get_header(string $key): ?string
    {
        return $this->headers[$key] ?? null;
    }

    public function get_headers(): array
    {
        return $this->headers;
    }

    public function set_header(string $key, string $value): void
    {
        $this->headers[$key] = $value;
    }

    // ArrayAccess for $req['id'] syntax
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->params[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->params[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->params[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->params[$offset]);
    }
}
