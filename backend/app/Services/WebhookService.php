<?php

declare(strict_types=1);

namespace PaymentForm\Services;

final class WebhookService
{
    private const REPLAY_WINDOW = 600; // 5 minutes
    private const REPLAY_NONCE_PREFIX = 'paymentform_webhook_nonce_';

    public function verify(string $rawBody, string $signatureHeader, string $secret): bool
    {
        // Parse header: t=<ts>,v1=<hmac>
        if (!preg_match('/^t=(\d+),v1=([a-f0-9]+)$/', $signatureHeader, $matches)) {
            return false;
        }

        $timestamp = (int) $matches[1];
        $providedHmac = $matches[2];

        $now = time();
        $ageDiff = abs($now - $timestamp);

        // Reject if timestamp is older than 5 minutes or newer than 60 seconds (clock skew tolerance)
        if ($ageDiff > 300 && $now > $timestamp) {
            return false;
        }

        if ($now < $timestamp && ($timestamp - $now) > 60) {
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

        // Mark nonce as used
        set_transient($nonceKey, true, self::REPLAY_WINDOW);

        return true;
    }
}
