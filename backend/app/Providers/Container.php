<?php

declare(strict_types=1);

namespace PaymentForm\Providers;

use ArrayAccess;
use InvalidArgumentException;
use OutOfBoundsException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Minimal lazy-singleton DI container. Replaces the abandoned Pimple package
 * with ~30 lines of vanilla PHP. Each binding is a factory closure invoked at
 * most once per container instance; subsequent reads return the shared value.
 *
 * Bound the same way Pimple was — `$c[Service::class] = static fn($c) => …`
 * — so the rest of the codebase didn't need to change shape when we swapped
 * the underlying implementation.
 *
 * @implements ArrayAccess<string, mixed>
 */
final class Container implements ArrayAccess
{
    /** @var array<string, callable(Container): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (! is_string($offset) || $offset === '') {
            throw new InvalidArgumentException('Container keys must be non-empty strings.');
        }
        if (! is_callable($value)) {
            throw new InvalidArgumentException(sprintf('Binding for "%s" must be callable.', esc_html($offset)));
        }

        $this->factories[$offset] = $value;
        unset($this->instances[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (! is_string($offset) || ! isset($this->factories[$offset])) {
            throw new OutOfBoundsException(sprintf('Container has no binding for "%s".', esc_html(is_string($offset) ? $offset : get_debug_type($offset))));
        }

        return $this->instances[$offset] ??= ($this->factories[$offset])($this);
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && isset($this->factories[$offset]);
    }

    public function offsetUnset(mixed $offset): void
    {
        if (is_string($offset)) {
            unset($this->factories[$offset], $this->instances[$offset]);
        }
    }
}
