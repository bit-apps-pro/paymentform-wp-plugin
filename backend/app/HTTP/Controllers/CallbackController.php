<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use JsonException;
use PaymentForm\Config;
use PaymentForm\Enums\ErrorCode;
use PaymentForm\Services\ConnectService;
use PaymentForm\Services\TokenServiceInterface;
use Throwable;

final class CallbackController
{
    /**
     * Upstream `error_code` is reflected into the admin URL as `?upstream=…`
     * and may be rendered by the admin SPA. Restrict to the canonical
     * UPPER_SNAKE shape so an attacker who controls the upstream response
     * (e.g. via a man-in-the-middle on a misconfigured site) can't inject
     * arbitrary characters into the redirect target.
     */
    private const UPSTREAM_CODE_PATTERN = '/^[A-Z_]{1,64}$/';

    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly ConnectService $connectService,
        private readonly TokenServiceInterface $tokenService,
    ) {}

    public function handle($req): void
    {
        $code = $req->get_param('code');
        $state = $req->get_param('state');

        if (! is_string($code) || $code === '' || strlen($code) > 128) {
            $this->redirectWithError(ErrorCode::MISSING_PARAMS);
            return;
        }
        if (! is_string($state) || $state === '' || strlen($state) !== 64) {
            $this->redirectWithError(ErrorCode::MISSING_PARAMS);
            return;
        }

        $userId = $this->connectService->consumeState($state);
        if ($userId === null) {
            $this->redirectWithError(ErrorCode::INVALID_STATE);
            return;
        }

        $response = wp_remote_request(Config::apiBaseUrl() . '/auth/exchange', [
            'method' => 'POST',
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'code' => $code,
                'site_url' => site_url(),
                'client_slug' => 'wp-plugin',
            ]),
        ]);

        if (is_wp_error($response)) {
            $this->debugLog('callback exchange error', $response->get_error_message());
            $this->redirectWithError(ErrorCode::EXCHANGE_FAILED);
            return;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);

        if ($status < 200 || $status >= 300) {
            $upstream = $this->extractUpstreamErrorCode($raw);
            $this->debugLog('callback exchange error', $upstream ?? sprintf('HTTP_%d', $status));
            $this->redirectWithError(ErrorCode::EXCHANGE_FAILED, $upstream);
            return;
        }

        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->debugLog('callback exchange error', $e->getMessage());
            $this->redirectWithError(ErrorCode::INVALID_RESPONSE);
            return;
        }

        $token = $body['data']['token'] ?? null;
        $expiresAt = $body['data']['expires_at'] ?? null;
        $webhookSecret = $body['data']['webhook_secret'] ?? null;
        $tenant = $body['data']['tenant'] ?? null;

        if (! is_string($token) || ! is_string($expiresAt)) {
            $this->redirectWithError(ErrorCode::INVALID_RESPONSE);
            return;
        }

        try {
            if (is_string($webhookSecret)) {
                $this->tokenService->storeWebhookSecret($webhookSecret);
            }
            $this->tokenService->store($token, $expiresAt, is_string($tenant) ? $tenant : null);
        } catch (Throwable $e) {
            $this->tokenService->clear();
            $this->debugLog('callback storage error', $e->getMessage());
            $this->redirectWithError(ErrorCode::STORAGE_FAILED);
            return;
        }

        $this->redirect(admin_url('admin.php?page=paymentform&connected=1'));
    }

    /**
     * Extract a trustworthy upstream `error_code` from a non-2xx exchange
     * response. Falsy / non-string / shape-violating values are dropped so
     * the redirect can't carry attacker-controlled content downstream.
     */
    private function extractUpstreamErrorCode(string $raw): ?string
    {
        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $upstream = is_array($body) ? ($body['error_code'] ?? null) : null;
        if (! is_string($upstream) || preg_match(self::UPSTREAM_CODE_PATTERN, $upstream) !== 1) {
            return null;
        }

        return $upstream;
    }

    private function redirectWithError(ErrorCode $errorCode, ?string $upstream = null): void
    {
        $query = ['page' => 'paymentform', 'error' => $errorCode->value];
        if ($upstream !== null) {
            $query['upstream'] = $upstream;
        }

        $this->redirect(admin_url('admin.php?' . http_build_query($query)));
    }

    /**
     * `wp_safe_redirect()` only sets the Location header — the REST dispatcher
     * will otherwise serialise our `void` return as a JSON body and append it
     * to the redirect response. Exit immediately so the redirect contract
     * holds and nothing else writes to the output stream.
     */
    private function redirect(string $url): void
    {
        wp_safe_redirect($url);
        exit;
    }

    private function debugLog(string $context, string $detail): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[paymentform] %s: %s', $context, $detail));
        }
    }
}
