<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Requests;

use PaymentForm\HTTP\Requests\VerifyOtpRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Drives VerifyOtpRequest::rules() through wp-validator make() — replacing the
 * controller-level six-digit guard that was removed when validation moved here.
 */
class VerifyOtpRequestTest extends TestCase
{
    public function test_rules_shape(): void
    {
        $this->assertSame(['otp' => ['required', 'digits:6']], (new VerifyOtpRequest())->rules());
    }

    public function test_passes_with_six_digit_code(): void
    {
        $request = new VerifyOtpRequest();

        $this->assertFalse($request->make(['otp' => '123456'], $request->rules())->fails());
    }

    public static function invalidProvider(): array
    {
        return [
            'missing' => [[]],
            'empty' => [['otp' => '']],
            'too short' => [['otp' => '12345']],
            'too long' => [['otp' => '1234567']],
            'non-digits' => [['otp' => 'abcdef']],
            'mixed' => [['otp' => '12a456']],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function test_fails_on_invalid_otp(array $data): void
    {
        $request = new VerifyOtpRequest();

        $this->assertTrue($request->make($data, $request->rules())->fails());
    }
}
