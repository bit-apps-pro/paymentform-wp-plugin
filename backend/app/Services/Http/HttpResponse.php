<?php

declare(strict_types=1);

namespace PaymentForm\Services\Http;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable, normalized result of an {@see HttpTransport::send()} call.
 *
 * `status` is `0` on transport failure (`isError()` is then true and `error`
 * holds the `WP_Error`). `body` is always an associative array — `[]` when the
 * response was empty, a non-array scalar, or malformed JSON. `rawBody` is the
 * raw response string, retained for error-message truncation. `headers` keys
 * are lowercased for case-insensitive lookups. `jsonError` is true only when a
 * non-empty body failed to parse as JSON.
 */
final class HttpResponse
{
    /**
     * @param  array<string, mixed>  $body     Decoded associative body; `[]` if empty/scalar/malformed.
     * @param  array<string, string>  $headers  Lowercased header keys.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly string $rawBody,
        public readonly array $headers,
        public readonly ?WP_Error $error = null,
        public readonly bool $jsonError = false,
    ) {}

    public function isError(): bool
    {
        return $this->error !== null;
    }
}
