<?php

declare(strict_types=1);

namespace PaymentForm\Services;

final class WebhookService
{
    private const FRESHNESS_WINDOW = 300; // 5 minutes: reject timestamps older than this
    private const FUTURE_TOLERANCE = 60;  // 60 seconds: allow timestamps up to this far in the future (clock skew)
    private const NONCE_TTL = self::FRESHNESS_WINDOW + self::FUTURE_TOLERANCE; // 360 seconds
    private const REPLAY_NONCE_PREFIX = 'paymentform_webhook_nonce_';

    public function verify(string $rawBody, string $signatureHeader, string $secret): bool
    {
        // Parse header: t=<ts>,v1=<hmac> where hmac is exactly 64 lowercase hex characters
        if (!preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $signatureHeader, $matches)) {
            return false;
        }

        $timestamp = (int) $matches[1];
        $providedHmac = $matches[2];

        $now = time();
        $ageDiff = abs($now - $timestamp);

        // Reject if timestamp is older than freshness window or newer than future tolerance (clock skew)
        if ($ageDiff > self::FRESHNESS_WINDOW && $now > $timestamp) {
            return false;
        }

        if ($now < $timestamp && ($timestamp - $now) > self::FUTURE_TOLERANCE) {
            return false;
        }

        // Compute expected HMAC
        $message = $timestamp . '.' . $rawBody;
        $expectedHmac = hash_hmac('sha256', $message, $secret);

        // Constant-time comparison
        if (!hash_equals($expectedHmac, $providedHmac)) {
            return false;
        }

        // Check for replay
        $nonceKey = self::REPLAY_NONCE_PREFIX . $providedHmac;
        if (get_transient($nonceKey) !== false) {
            return false;
        }

        // Mark nonce as used (TTL = freshness window + future tolerance)
        set_transient($nonceKey, true, self::NONCE_TTL);

        return true;
    }
}
