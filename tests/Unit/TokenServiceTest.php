<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit;

use PaymentForm\Services\EncryptionService;
use PaymentForm\Services\TokenService;
use PHPUnit\Framework\TestCase;

class TokenServiceTest extends TestCase
{
    private TokenService $svc;
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->encryption = new EncryptionService();
        $this->svc = new TokenService($this->encryption);
        \TestWpFunctions::reset();
    }

    public function test_get_returns_null_when_no_token_stored(): void
    {
        $this->assertNull($this->svc->get());
    }

    public function test_store_and_get(): void
    {
        $token = 'test-token-123';
        $expiresAt = (new \DateTime('+30 days'))->format(\DateTimeInterface::ATOM);

        $this->svc->store($token, $expiresAt);

        $retrieved = $this->svc->get();
        $this->assertSame($token, $retrieved);
    }

    public function test_clear_removes_token(): void
    {
        $token = 'test-token-456';
        $expiresAt = (new \DateTime('+30 days'))->format(\DateTimeInterface::ATOM);

        $this->svc->store($token, $expiresAt);
        $this->assertSame($token, $this->svc->get());

        $this->svc->clear();
        $this->assertNull($this->svc->get());
    }

    public function test_expires_in_returns_null_when_no_token(): void
    {
        $this->assertNull($this->svc->expiresIn());
    }

    public function test_expires_in_returns_seconds_remaining(): void
    {
        $now = new \DateTime();
        $expiresAt = (clone $now)->add(new \DateInterval('PT1H'));
        $token = 'test-token';

        $this->svc->store($token, $expiresAt->format(\DateTimeInterface::ATOM));

        $secondsRemaining = $this->svc->expiresIn();
        $this->assertNotNull($secondsRemaining);
        // Should be roughly 3600 seconds (1 hour), with small tolerance for test execution time
        $this->assertGreaterThan(3595, $secondsRemaining);
        $this->assertLessThanOrEqual(3600, $secondsRemaining);
    }

    public function test_expires_in_returns_zero_when_expired(): void
    {
        $expiresAt = (new \DateTime('-1 hour'))->format(\DateTimeInterface::ATOM);
        $this->svc->store('token', $expiresAt);

        $secondsRemaining = $this->svc->expiresIn();
        $this->assertSame(0, $secondsRemaining);
    }

    public function test_needs_refresh_false_when_no_token(): void
    {
        // Ensure no token is stored
        $this->svc->clear();
        $this->assertFalse($this->svc->needsRefresh());
    }

    public function test_needs_refresh_false_when_far_from_expiry(): void
    {
        $expiresAt = (new \DateTime('+10 days'))->format(\DateTimeInterface::ATOM);
        $this->svc->store('token', $expiresAt);

        $this->assertFalse($this->svc->needsRefresh(300));
    }

    public function test_needs_refresh_true_when_within_threshold(): void
    {
        // Expires in 100 seconds, threshold is 300 seconds
        $expiresAt = (new \DateTime('+100 seconds'))->format(\DateTimeInterface::ATOM);
        $this->svc->store('token', $expiresAt);

        $this->assertTrue($this->svc->needsRefresh(300));
    }

    public function test_needs_refresh_true_when_expired(): void
    {
        $expiresAt = (new \DateTime('-1 hour'))->format(\DateTimeInterface::ATOM);
        $this->svc->store('token', $expiresAt);

        $this->assertTrue($this->svc->needsRefresh(300));
    }

    public function test_get_raw_returns_encrypted_blob(): void
    {
        $token = 'test-token';
        $expiresAt = (new \DateTime('+30 days'))->format(\DateTimeInterface::ATOM);

        $this->svc->store($token, $expiresAt);

        $raw = $this->svc->getRaw();
        $this->assertIsArray($raw);
        $this->assertArrayHasKey('ciphertext', $raw);
        $this->assertArrayHasKey('iv', $raw);
        $this->assertArrayHasKey('tag', $raw);
        $this->assertArrayHasKey('expires_at', $raw);
        $this->assertSame($expiresAt, $raw['expires_at']);
    }

    public function test_webhook_secret_storage(): void
    {
        $secret = 'webhook-secret-xyz';
        $token = 'test-token';
        $expiresAt = (new \DateTime('+30 days'))->format(\DateTimeInterface::ATOM);

        $this->svc->store($token, $expiresAt);
        $this->svc->storeWebhookSecret($secret);

        $retrieved = $this->svc->getWebhookSecret();
        $this->assertSame($secret, $retrieved);
    }

    public function test_webhook_secret_returns_null_when_not_stored(): void
    {
        // Create a fresh service without webhook secret
        $this->svc->clear();
        $this->assertNull($this->svc->getWebhookSecret());
    }

    public function test_store_persists_tenant(): void
    {
        $token = 'test-token';
        $expiresAt = (new \DateTime('+30 days'))->format(\DateTimeInterface::ATOM);
        $tenant = 'ws-test';

        $this->svc->store($token, $expiresAt, $tenant);

        $this->assertSame($tenant, $this->svc->getTenant());
    }

    public function test_get_tenant_returns_null_when_no_tenant(): void
    {
        $token = 'test-token';
        $expiresAt = (new \DateTime('+30 days'))->format(\DateTimeInterface::ATOM);

        $this->svc->store($token, $expiresAt);

        $this->assertNull($this->svc->getTenant());
    }
}
