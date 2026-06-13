<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Requests;

use PaymentForm\HTTP\Requests\Rules\CountryCodeRule;
use PaymentForm\HTTP\Requests\SetupRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Drives SetupRequest::rules() through the wp-validator make() pipeline — the
 * same validation wp-kit's RouteRegister runs before SetupController::handle().
 * These cases replace the controller-level "bad input → 400" tests that were
 * removed when the guards moved out of the controller.
 */
class SetupRequestTest extends TestCase
{
    private const VALID = ['email' => 'admin@example.com', 'country' => 'US'];

    public function test_rules_shape(): void
    {
        $this->assertSame(
            [
                'email' => ['required', 'email', 'max:256'],
                'country' => ['required', CountryCodeRule::class],
                'locale' => ['nullable', 'string', 'size:5'],
                'email_override' => ['nullable', 'email', 'max:256'],
            ],
            (new SetupRequest())->rules()
        );
    }

    public function test_passes_with_minimal_valid_input(): void
    {
        $request = new SetupRequest();

        $this->assertFalse($request->make(self::VALID, $request->rules())->fails());
    }

    public function test_passes_with_optional_locale_and_override(): void
    {
        $request = new SetupRequest();
        $data = [...self::VALID, 'locale' => 'en_US', 'email_override' => 'owner@example.com'];

        $this->assertFalse($request->make($data, $request->rules())->fails());
    }

    public static function invalidProvider(): array
    {
        return [
            'missing email' => [['country' => 'US']],
            'malformed email' => [['email' => 'not-an-email', 'country' => 'US']],
            'oversized email' => [['email' => str_repeat('a', 250) . '@example.com', 'country' => 'US']],
            'missing country' => [['email' => 'admin@example.com']],
            'lowercase country' => [['email' => 'admin@example.com', 'country' => 'us']],
            'three-letter country' => [['email' => 'admin@example.com', 'country' => 'USA']],
            'alphanumeric country' => [['email' => 'admin@example.com', 'country' => 'A1']],
            'numeric country' => [['email' => 'admin@example.com', 'country' => '12']],
            'short locale' => [[...self::VALID, 'locale' => 'en']],
            'malformed override' => [[...self::VALID, 'email_override' => 'not-an-email']],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function test_fails_on_invalid_input(array $data): void
    {
        $request = new SetupRequest();

        $this->assertTrue($request->make($data, $request->rules())->fails());
    }
}
