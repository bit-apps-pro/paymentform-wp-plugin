<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use PaymentForm\Config;
use PaymentForm\Deps\BitApps\WPKit\Http\Response;
use PaymentForm\Enums\ErrorCode;
use PaymentForm\HTTP\Requests\LoginLinkRequest;
use PaymentForm\HTTP\Requests\Rules\RedirectPathRule;
use PaymentForm\Providers\ServiceContainer;
use PaymentForm\Services\ApiClient;
use PaymentForm\Services\DisconnectedException;
use RuntimeException;

/**
 * POST /paymentform/v1/login-link — mints a one-time dashboard SSO login link.
 *
 * Bridges to the backend mint endpoint `POST /auth/login-link` (auth:sanctum,
 * bearer) carrying an optional, server-validated `redirect`. The backend
 * returns the full single-origin dashboard URL (the `/auth/sso?ticket=...`
 * link on the app host); we forward only the `url` to the SPA, which opens it
 * in a new tab. `/auth/` paths are NOT tenant-prefixed by {@see ApiClient}, so
 * the mint hits the central domain as required.
 *
 * Mirrors {@see FormsProxyController}: transport failures map onto the SPA
 * error envelope (`error_code`) so the caller can fall back to `/signin`
 * (spec §L3). A 401 during mint triggers ApiClient's one-shot refresh+retry
 * (`handle401`); a second 401 surfaces as DisconnectedException → DISCONNECTED.
 */
final class LoginLinkController
{
    /**
     * Default dashboard destination when no (valid) redirect is supplied.
     */
    private const DEFAULT_REDIRECT = '/myforms';

    private readonly ApiClient $apiClient;

    public function __construct()
    {
        $this->apiClient = ServiceContainer::get(ApiClient::class);
    }

    /**
     * @return array{url: string}|Response
     */
    public function create(LoginLinkRequest $req): array|Response
    {
        $redirect = $this->resolveRedirect($req->get('redirect'));

        try {
            $response = $this->apiClient->request('POST', '/auth/login-link', [
                'json' => ['redirect' => $redirect],
            ]);
        } catch (DisconnectedException) {
            return $this->errorResponse(ErrorCode::DISCONNECTED, 401);
        } catch (RuntimeException $e) {
            return $this->errorResponse(ErrorCode::API_ERROR, 502, $e->getMessage());
        }

        // Backend envelope: { status, data: { url, expires_in }, message }.
        $url = $response['data']['url'] ?? null;
        if (! is_string($url) || ! $this->isAppUrl($url)) {
            return $this->errorResponse(ErrorCode::INVALID_RESPONSE, 502);
        }

        return ['url' => $url];
    }

    /**
     * True only for an absolute URL on the configured dashboard origin; the SPA opens it in a
     * tab that starts on wp-admin's origin, so a foreign or `javascript:` URL must never pass.
     */
    private function isAppUrl(string $url): bool
    {
        $urlOrigin = self::originOf($url);

        return $urlOrigin !== null && $urlOrigin === self::originOf(Config::appBaseUrl());
    }

    /**
     * Lower-cased `scheme://host[:port]` of an http(s) URL, or null when it has none.
     */
    private static function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            return null;
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return strtolower($parts['scheme'].'://'.$parts['host'].$port);
    }

    /**
     * Defensive server-side redirect resolution (spec §L1). The router runs
     * {@see LoginLinkRequest::rules()} before this action, but a direct action
     * call (and defense-in-depth) means the controller must not trust the raw
     * value: any redirect that fails the {@see RedirectPathRule} allowlist —
     * including a missing or non-string one — falls back to the safe default.
     */
    private function resolveRedirect(mixed $raw): string
    {
        return (new RedirectPathRule())->validate($raw)
            ? (string) $raw
            : self::DEFAULT_REDIRECT;
    }

    /**
     * Build the SPA error envelope for a mint failure. Mirrors
     * {@see FormsProxyController::errorResponse}: `error_code` rides both the
     * body and the wp-kit envelope `code`; exception messages (which may carry
     * internal URLs) are only forwarded under WP_DEBUG. `message(null)` clears
     * any message left on wp-kit's all-static Response singleton.
     */
    private function errorResponse(ErrorCode $errorCode, int $status, ?string $message = null): Response
    {
        $body = ['status' => false, 'error_code' => $errorCode->value];

        if ($message !== null && defined('WP_DEBUG') && WP_DEBUG) {
            $body['message'] = $message;
        }

        return Response::error($body, $status)->code($errorCode->value)->message(null);
    }
}
