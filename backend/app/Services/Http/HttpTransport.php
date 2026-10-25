<?php

declare(strict_types=1);

namespace PaymentForm\Services\Http;

use JsonException;
use PaymentForm\Deps\BitApps\WPKit\Http\Client\HttpClient;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin adapter over the vendored wp-kit {@see HttpClient}, the plugin's single
 * outbound HTTP transport. It normalizes the client's quirks back into the
 * array-shaped contract the rest of the codebase expects:
 *
 *   - the HTTP status lives on a getter, not in the return value;
 *   - bodies decode to `stdClass` (or fall back to the raw string when the
 *     decoded value is "empty" — `HttpClient::request` does
 *     `empty($decoded) ? $rawBody : $decoded`, so `0`/`false`/`""`/`[]`/`{}`
 *     and malformed JSON all yield the raw string);
 *   - response headers may arrive as a case-insensitive dictionary object;
 *   - JSON decoding is lenient (no `JSON_THROW_ON_ERROR`).
 *
 * The adapter therefore performs its own strict decode so callers can rely on
 * an associative-array body and a `jsonError` flag, and lowercases header keys
 * for case-insensitive lookups.
 *
 * The transport owns the API base URL (normalized once, trailing slash
 * stripped). Callers pass a leading-slash RELATIVE path; a fresh
 * {@see HttpClient} is created per call with `base_uri => $this->baseUrl` and
 * the path passed as the verb argument, so wp-kit concatenates `base_uri . path`
 * — byte-for-byte equal to the legacy `rtrim($base, '/') . $path`.
 */
final class HttpTransport
{
    private readonly string $baseUrl;

    /** Base URL host wp-kit may reach despite failing WP's safe-URL check, or null for safe requests only. */
    private readonly ?string $privateBaseHost;

    /**
     * @param  string  $baseUrl  Upstream API base; the trailing slash is stripped
     *                           once so per-call paths (leading slash) concatenate
     *                           cleanly.
     * @param  bool  $allowPrivateBaseHost  Dev-only: lets requests reach the base host
     *                                      when it resolves to a private/loopback IP or
     *                                      uses a non-standard port.
     */
    public function __construct(string $baseUrl = 'https://api.paymentform.io', bool $allowPrivateBaseHost = false)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->privateBaseHost = $allowPrivateBaseHost ? (wp_parse_url($this->baseUrl, PHP_URL_HOST) ?: null) : null;
    }

    /**
     * GET a relative path. Thin convenience wrapper over {@see send()}.
     *
     * @param  array<string, string>  $headers  Canonical-cased keys only.
     */
    public function get(string $path, array $headers = [], int $timeoutSeconds = 30): HttpResponse
    {
        return $this->send('GET', $path, $headers, timeoutSeconds: $timeoutSeconds);
    }

    /**
     * POST a relative path. Thin convenience wrapper over {@see send()}.
     *
     * @param  array<string, string>  $headers  Canonical-cased keys only.
     * @param  array<string, mixed>|null  $json   Associative payload to JSON-encode.
     * @param  string|null  $body  Pre-encoded raw body (ignored when `$json` is set).
     */
    public function post(
        string $path,
        array $headers = [],
        ?array $json = null,
        ?string $body = null,
        int $timeoutSeconds = 30,
    ): HttpResponse {
        return $this->send('POST', $path, $headers, json: $json, body: $body, timeoutSeconds: $timeoutSeconds);
    }

    /**
     * Dispatch a single request against `baseUrl . $path` and return the
     * normalized response.
     *
     * Exactly one of `$json` / `$body` should be supplied (or neither). When
     * `$json` is given it is encoded and a `Content-Type: application/json`
     * header is set, matching the legacy `wp_remote_request` argument shape.
     *
     * @param  string  $path  Leading-slash relative path; the transport prepends `baseUrl`.
     * @param  array<string, string>  $headers  Canonical-cased keys only
     *                                           (`Authorization`, `Accept`, `Content-Type`).
     * @param  array<string, mixed>|null  $json   Associative payload to JSON-encode.
     * @param  string|null  $body  Pre-encoded raw body (ignored when `$json` is set).
     */
    public function send(
        string $method,
        string $path,
        array $headers = [],
        ?array $json = null,
        ?string $body = null,
        int $timeoutSeconds = 30,
    ): HttpResponse {
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = (string) wp_json_encode($json);
        }

        $client = new HttpClient(['base_uri' => $this->baseUrl]);
        $client->setHeaders($headers);
        // `getPreparedPayload` returns the string body verbatim; an empty string
        // keeps that string path (avoids the array-merge branch that would emit
        // a spurious `[]`/`{}` body for payload-less requests).
        $client->setBody($body ?? '');
        // Timeout is the only option safe to pass: `wp_parse_args` fills the
        // remaining defaults (method/headers/body) from the builder. Passing
        // method/headers/body here would override the builder's values.
        $client->setOptions(['timeout' => $timeoutSeconds]);
        if ($this->privateBaseHost !== null) {
            // wp-kit allowlists by host and disables redirects, so only the configured base is reachable.
            $client->allowUnsafeUrls(true, [$this->privateBaseHost]);
        }

        // wp-kit concatenates `base_uri . $path`; with the base trailing slash
        // stripped and a leading-slash path this equals the legacy full URL.
        $decoded = $client->{strtolower($method)}($path);

        if ($decoded instanceof WP_Error) {
            return new HttpResponse(
                status: 0,
                body: [],
                rawBody: '',
                headers: [],
                error: $decoded,
            );
        }

        $rawBody = $this->extractRawBody($decoded);
        [$assocBody, $jsonError] = $this->decodeBody($rawBody);

        return new HttpResponse(
            status: (int) $client->getResponseCode(),
            body: $assocBody,
            rawBody: $rawBody,
            headers: $this->normalizeHeaders($client->getResponseHeaders()),
            jsonError: $jsonError,
        );
    }

    /**
     * Recover the raw response string. `HttpClient::request` returns either the
     * decoded `stdClass`/array (truthy decode) or the raw body string (empty
     * decode / malformed). Re-encode the decoded value so we own a single,
     * canonical string to strictly decode; preserve genuine strings verbatim.
     *
     * @param  mixed  $decoded
     */
    private function extractRawBody($decoded): string
    {
        if (is_string($decoded)) {
            return $decoded;
        }

        if ($decoded === null) {
            return '';
        }

        return (string) wp_json_encode($decoded);
    }

    /**
     * Strict-decode to an associative array. A body that is empty, malformed,
     * or decodes to a non-array scalar yields `[]`; malformed JSON additionally
     * sets the `jsonError` flag so callers can branch on invalid responses.
     *
     * @return array{0: array<string, mixed>, 1: bool}  [body, jsonError]
     */
    private function decodeBody(string $rawBody): array
    {
        if ($rawBody === '') {
            return [[], false];
        }

        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [[], true];
        }

        return [is_array($decoded) ? $decoded : [], false];
    }

    /**
     * Flatten the client's response headers into `array<string, string>` with
     * lowercased keys for case-insensitive lookups. Accepts a plain array or a
     * Requests/WP case-insensitive dictionary (anything iterable); array-valued
     * headers collapse to their first element, matching the single-value
     * semantics of `wp_remote_retrieve_header`.
     *
     * @param  mixed  $headers
     * @return array<string, string>
     */
    private function normalizeHeaders($headers): array
    {
        if (!is_iterable($headers)) {
            return [];
        }

        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtolower((string) $key)] = is_array($value)
                ? (string) ($value[0] ?? '')
                : (string) $value;
        }

        return $normalized;
    }
}
