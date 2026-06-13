<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Requests\Rules;

use PaymentForm\HTTP\Requests\Rules\CountryCodeRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CountryCodeRuleTest extends TestCase
{
    public static function validProvider(): array
    {
        return [
            'US' => ['US'],
            'GB' => ['GB'],
            'ZZ' => ['ZZ'],
        ];
    }

    #[DataProvider('validProvider')]
    public function test_accepts_alpha2_uppercase(string $code): void
    {
        $this->assertTrue((new CountryCodeRule())->validate($code));
    }

    public static function invalidProvider(): array
    {
        return [
            'lowercase' => ['us'],
            'three letters' => ['USA'],
            'one letter' => ['U'],
            'alphanumeric' => ['A1'],
            'numeric' => ['12'],
            'punctuation' => ['-_'],
            'multibyte two bytes' => ['é'],
            'empty string' => [''],
            'non-string int' => [12],
            'non-string null' => [null],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function test_rejects_non_alpha2(mixed $value): void
    {
        $this->assertFalse((new CountryCodeRule())->validate($value));
    }

    public function test_message_names_the_attribute_placeholder(): void
    {
        $this->assertSame(
            'The :attribute must be a valid ISO 3166-1 alpha-2 country code.',
            (new CountryCodeRule())->message()
        );
    }
}
