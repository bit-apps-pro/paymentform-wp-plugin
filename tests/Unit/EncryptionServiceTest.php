<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit;

use PaymentForm\Services\EncryptionService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class EncryptionServiceTest extends TestCase
{
    private EncryptionService $svc;

    protected function setUp(): void
    {
        $this->svc = new EncryptionService();
    }

    public function test_roundtrip(): void
    {
        $plaintext = 'super-secret-token-1234567890';
        $encrypted = $this->svc->encrypt($plaintext);

        $this->assertArrayHasKey('ciphertext', $encrypted);
        $this->assertArrayHasKey('iv', $encrypted);
        $this->assertArrayHasKey('tag', $encrypted);

        $decrypted = $this->svc->decrypt($encrypted['ciphertext'], $encrypted['iv'], $encrypted['tag']);
        $this->assertSame($plaintext, $decrypted);
    }

    public function test_different_contexts_produce_different_keys(): void
    {
        $plaintext = 'secret';
        $encA = $this->svc->encrypt($plaintext, 'context:a');
        $encB = $this->svc->encrypt($plaintext, 'context:b');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('decrypt_failed');
        $this->svc->decrypt($encA['ciphertext'], $encA['iv'], $encA['tag'], 'context:b');
    }

    public function test_tampered_ciphertext_throws(): void
    {
        $encrypted = $this->svc->encrypt('hello');

        // Flip last byte of ciphertext
        $raw       = base64_decode($encrypted['ciphertext']);
        $raw       = substr_replace($raw, chr(ord($raw[strlen($raw) - 1]) ^ 1), -1, 1);
        $tampered  = base64_encode($raw);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('decrypt_failed');
        $this->svc->decrypt($tampered, $encrypted['iv'], $encrypted['tag']);
    }

    public function test_iv_is_random_per_encrypt(): void
    {
        $a = $this->svc->encrypt('same-input');
        $b = $this->svc->encrypt('same-input');
        $this->assertNotSame($a['iv'], $b['iv']);
        $this->assertNotSame($a['ciphertext'], $b['ciphertext']);
    }
}
