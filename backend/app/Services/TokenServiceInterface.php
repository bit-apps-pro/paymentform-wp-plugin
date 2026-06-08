<?php

declare(strict_types=1);

namespace PaymentForm\Services;

interface TokenServiceInterface
{
    public function get(): ?string;

    public function store(string $token, string $expiresAt, ?string $tenant = null): void;

    public function clear(): void;

    public function expiresIn(): ?int;

    public function needsRefresh(int $thresholdSeconds = 300): bool;

    public function getRaw(): ?array;

    public function storeWebhookSecret(string $secret): void;

    public function getWebhookSecret(): ?string;

    public function getTenant(): ?string;
}
