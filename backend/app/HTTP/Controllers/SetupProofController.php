<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use PaymentForm\Services\SetupOptionsStore;
use WP_REST_Request;

/**
 * HTTP-01-style proof-of-control handler for the setup flow.
 *
 * The backend's SetupProofService::handshake() calls this endpoint at
 * GET /paymentform/setup-proof?nonce=<server_nonce>&site=<site_url>
 * with header X-Setup-Token: <wp_setup_token>.
 *
 * The X-Setup-Token is self-describing: payload = "<site_url>:<wp_nonce>:<expires_at>"
 * and embeds an HMAC under SECURE_AUTH_KEY. We parse the payload, validate the
 * HMAC, lookup the row by the embedded wp_nonce, atomically consume it, and
 * echo back the server-supplied `nonce` query param so the backend can prove
 * the round-trip.
 */
final class SetupProofController
{
    public function handle(WP_REST_Request $req): void
    {
        $serverNonce = (string) ($req->get_param('nonce') ?? '');
        $site = (string) ($req->get_param('site') ?? '');
        $headerToken = (string) ($req->get_header('X-Setup-Token') ?? '');

        if (!$serverNonce || !$site || !$headerToken) {
            $this->respondAndExit(['error' => 'bad_request'], 400);
        }

        $parsed = $this->parseToken($headerToken);
        if ($parsed === null) {
            $this->respondAndExit(['error' => 'bad_token_shape'], 401);
        }

        [$tokenSiteUrl, $wpNonce, $tokenExpiresAt, $providedHmac] = $parsed;

        // Recompute HMAC and compare in constant time.
        $expectedHmac = hash_hmac(
            'sha256',
            $tokenSiteUrl . ':' . $wpNonce . ':' . $tokenExpiresAt,
            SECURE_AUTH_KEY
        );

        if (!hash_equals($expectedHmac, $providedHmac)) {
            $this->respondAndExit(['error' => 'bad_hmac'], 401);
        }

        if ($tokenExpiresAt < time()) {
            $this->respondAndExit(['error' => 'setup_token_expired'], 410);
        }

        // Site URL in token must match the request param.
        $normalizedSite = rtrim($site, '/');
        $normalizedTokenSite = rtrim($tokenSiteUrl, '/');
        if ($normalizedSite !== $normalizedTokenSite) {
            $this->respondAndExit(['error' => 'site_mismatch'], 401);
        }

        // Lookup the persisted row by the embedded wp_nonce (not the backend's nonce).
        $store = new SetupOptionsStore();
        $row = $store->getByNonce($wpNonce);

        if ($row === null) {
            $this->respondAndExit(['error' => 'setup_token_unknown'], 410);
        }

        // Defense-in-depth: stored site_url should also match the token payload.
        $normalizedRowSite = rtrim((string) ($row['site_url'] ?? ''), '/');
        if ($normalizedRowSite !== $normalizedTokenSite) {
            $this->respondAndExit(['error' => 'site_mismatch'], 401);
        }

        // Atomically consume the nonce row. If delete_option returns false,
        // another worker raced us and already consumed it.
        if (!$store->consumeByNonce($wpNonce)) {
            $this->respondAndExit(['error' => 'setup_token_consumed'], 410);
        }

        // Success: echo the server-supplied nonce + opaque echo string + site_url + expiry.
        $response = [
            'nonce' => $serverNonce,
            'echo' => 'paymentform:setup:v1',
            'site_url' => site_url(),
            'expires_at' => gmdate('c', time() + 30),
        ];

        $this->respondAndExit($response, 200);
    }

    /**
     * Parse "v1.<b64(site_url:nonce:expires_at)>.<hex_hmac>" into its components.
     *
     * @return array{0:string,1:string,2:int,3:string}|null
     */
    private function parseToken(string $token): ?array
    {
        $parts = explode('.', $token, 3);
        if (count($parts) !== 3 || $parts[0] !== 'v1') {
            return null;
        }

        $payloadB64 = $parts[1];
        $hmac = $parts[2];

        $payload = base64_decode($payloadB64, true);
        if ($payload === false) {
            return null;
        }

        $segments = explode(':', $payload);
        if (count($segments) < 3) {
            return null;
        }

        // Reassemble site_url if it contained colons (e.g. http://host).
        $tokenExpiresAt = (int) array_pop($segments);
        $nonce = (string) array_pop($segments);
        $siteUrl = implode(':', $segments);

        if ($siteUrl === '' || $nonce === '' || $tokenExpiresAt <= 0) {
            return null;
        }

        return [$siteUrl, $nonce, $tokenExpiresAt, $hmac];
    }

    private function respondAndExit(array $body, int $statusCode): void
    {
        status_header($statusCode);
        nocache_headers();
        header('Content-Type: application/json');
        echo wp_json_encode($body);
        exit;
    }
}
