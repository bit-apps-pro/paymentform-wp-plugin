<?php

declare(strict_types=1);

namespace PaymentForm\Services;

use PaymentForm\Services\Http\HttpResponse;
use PaymentForm\Services\Http\HttpTransport;

/**
 * Unauthenticated HTTP client for the pre-auth setup endpoints
 * (`/auth/setup`, `/auth/setup/finalize`, `/auth/setup/diagnose`,
 * `/auth/exchange`). It centralizes the base URL, the JSON `Accept` header and
 * the per-call timeout over the shared {@see HttpTransport} adapter.
 *
 * Unlike {@see ApiClient}, this client adds no bearer token, no tenant path
 * prefix and no 401 refresh handling — these endpoints run before a token
 * exists. Every response and branch semantic stays in the calling controllers;
 * this is transport consolidation only.
 */
final class SetupApiClient
{
    public function __construct(
        private readonly HttpTransport $http,
    ) {}

    /**
     * POST a JSON body to an unauthenticated `/auth/*` path and return the
     * normalized response. The transport prepends the base URL, sets
     * `Content-Type: application/json` and encodes the body when `$json` is
     * passed; this client adds the JSON `Accept` header.
     *
     * @param  string  $path  Leading-slash relative path; the transport prepends the base URL.
     * @param  array<string, mixed>  $json
     */
    public function post(string $path, array $json, int $timeoutSeconds = 30): HttpResponse
    {
        return $this->http->post(
            $path,
            ['Accept' => 'application/json'],
            json: $json,
            timeoutSeconds: $timeoutSeconds,
        );
    }
}
