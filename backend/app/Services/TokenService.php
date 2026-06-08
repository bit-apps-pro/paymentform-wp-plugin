<?php

declare(strict_types=1);

namespace PaymentForm\Services;

use RuntimeException;

final class TokenService implements TokenServiceInterface
{
    private const OPTION_NAME = 'paymentform_credentials';
    private const CONTEXT_TOKEN = 'paymentform:token:v1';
    private const CONTEXT_WEBHOOK = 'paymentform:webhook:v1';

    public function __construct(
        private readonly EncryptionService $encryption,
    ) {}

    public function get(): ?string
    {
        $data = $this->getRaw();
        if ($data === null) {
            return null;
        }

        try {
            return $this->encryption->decrypt(
                $data['ciphertext'],
                $data['iv'],
                $data['tag'],
                self::CONTEXT_TOKEN
            );
        } catch (RuntimeException) {
            return null;
        }
    }

    public function store(string $token, string $expiresAt): void
    {
        $data = $this->getRaw() ?? [];

        // Encrypt token
        $encrypted = $this->encryption->encrypt($token, self::CONTEXT_TOKEN);

        $data['ciphertext'] = $encrypted['ciphertext'];
        $data['iv'] = $encrypted['iv'];
        $data['tag'] = $encrypted['tag'];
        $data['expires_at'] = $expiresAt;
        $data['connected_at'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        update_option(self::OPTION_NAME, $data, false);
    }

    public function clear(): void
    {
        delete_option(self::OPTION_NAME);
    }

    public function expiresIn(): ?int
    {
        $data = $this->getRaw();
        if ($data === null || !isset($data['expires_at'])) {
            return null;
        }

        $expiresAt = strtotime($data['expires_at']);
        if ($expiresAt === false) {
            return null;
        }

        $secondsRemaining = $expiresAt - time();
        return max(0, $secondsRemaining);
    }

    public function needsRefresh(int $thresholdSeconds = 300): bool
    {
        $secondsRemaining = $this->expiresIn();
        if ($secondsRemaining === null) {
            return false;
        }

        // Needs refresh if expiry is within threshold
        return $secondsRemaining <= $thresholdSeconds;
    }

    public function getRaw(): ?array
    {
        $data = get_option(self::OPTION_NAME);
        if ($data === false || !is_array($data)) {
            return null;
        }
        return $data;
    }

    public function storeWebhookSecret(string $secret): void
    {
        $data = $this->getRaw() ?? [];

        $encrypted = $this->encryption->encrypt($secret, self::CONTEXT_WEBHOOK);

        $data['webhook_secret_ciphertext'] = $encrypted['ciphertext'];
        $data['webhook_secret_iv'] = $encrypted['iv'];
        $data['webhook_secret_tag'] = $encrypted['tag'];

        update_option(self::OPTION_NAME, $data, false);
    }

    public function getWebhookSecret(): ?string
    {
        $data = $this->getRaw();
        if ($data === null) {
            return null;
        }

        if (!isset($data['webhook_secret_ciphertext'], $data['webhook_secret_iv'], $data['webhook_secret_tag'])) {
            return null;
        }

        try {
            return $this->encryption->decrypt(
                $data['webhook_secret_ciphertext'],
                $data['webhook_secret_iv'],
                $data['webhook_secret_tag'],
                self::CONTEXT_WEBHOOK
            );
        } catch (RuntimeException) {
            return null;
        }
    }
}
