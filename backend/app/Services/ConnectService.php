<?php

declare(strict_types=1);

namespace PaymentForm\Services;

final class ConnectService
{
    private const STATE_TRANSIENT_PREFIX = 'paymentform_connect_state_';
    private const STATE_TTL = 600; // 10 minutes

    public function generateState(): string
    {
        $state = bin2hex(random_bytes(32));
        $user = wp_get_current_user();
        $userId = $user->ID ?? 0;

        $data = [
            'user_id' => $userId,
            'created_at' => time(),
        ];

        set_transient(self::STATE_TRANSIENT_PREFIX . $state, $data, self::STATE_TTL);

        return $state;
    }

    public function consumeState(string $state): ?int
    {
        $data = get_transient(self::STATE_TRANSIENT_PREFIX . $state);

        if ($data === false || !is_array($data)) {
            return null;
        }

        delete_transient(self::STATE_TRANSIENT_PREFIX . $state);

        return $data['user_id'] ?? null;
    }

    public function buildConnectUrl(string $appBaseUrl, string $callbackUrl, string $state): string
    {
        $siteUrl = home_url();
        $params = [
            'site_url' => $siteUrl,
            'callback_url' => $callbackUrl,
            'state' => $state,
        ];

        return $appBaseUrl . '/connect?' . http_build_query($params);
    }
}
