<?php

declare(strict_types=1);

namespace PaymentForm\Services;

use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

final class EncryptionService
{
    private const ALGO    = 'aes-256-gcm';
    private const KEY_LEN = 32;
    private const IV_LEN  = 12;
    private const TAG_LEN = 16;

    public function encrypt(string $plaintext, string $context = 'paymentform:token:v1'): array
    {
        $key = $this->deriveKey($context);
        $iv  = random_bytes(self::IV_LEN);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext, self::ALGO, $key,
            OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN
        );

        if ($ciphertext === false) {
            throw new RuntimeException('encrypt_failed');
        }

        return [
            'ciphertext' => base64_encode($ciphertext),
            'iv'         => base64_encode($iv),
            'tag'        => base64_encode($tag),
        ];
    }

    public function decrypt(string $ciphertext, string $iv, string $tag, string $context = 'paymentform:token:v1'): string
    {
        $key       = $this->deriveKey($context);
        $rawCipher = base64_decode($ciphertext, true);
        $rawIv     = base64_decode($iv, true);
        $rawTag    = base64_decode($tag, true);

        if ($rawCipher === false || $rawIv === false || $rawTag === false) {
            throw new RuntimeException('invalid_base64');
        }

        $plaintext = openssl_decrypt(
            $rawCipher, self::ALGO, $key,
            OPENSSL_RAW_DATA, $rawIv, $rawTag
        );

        if ($plaintext === false) {
            throw new RuntimeException('decrypt_failed');
        }

        return $plaintext;
    }

    private function deriveKey(string $context): string
    {
        if (!defined('AUTH_KEY') || !defined('AUTH_SALT')) {
            throw new RuntimeException('wp_salts_missing');
        }

        if (strlen(AUTH_KEY) < 32 || strlen(AUTH_SALT) < 32) {
            throw new RuntimeException('wp_salts_too_short');
        }

        return hash_hkdf('sha256', AUTH_KEY . AUTH_SALT, self::KEY_LEN, $context);
    }
}
