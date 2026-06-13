<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Requests\Rules;

use PaymentForm\HTTP\Requests\Rules\RedirectPathRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RedirectPathRuleTest extends TestCase
{
    public static function validProvider(): array
    {
        return [
            'myforms' => ['/myforms'],
            'build with uuid' => ['/build/11111111-1111-4111-8111-111111111111'],
            'root only' => ['/'],
            'nested path with underscore' => ['/forms/my_form-1'],
            'deep path' => ['/a/b/c'],
        ];
    }

    #[DataProvider('validProvider')]
    public function test_accepts_safe_relative_paths(string $path): void
    {
        $this->assertTrue((new RedirectPathRule())->validate($path));
    }

    public static function invalidProvider(): array
    {
        return [
            'protocol-relative' => ['//evil.example'],
            'absolute http' => ['https://evil.example'],
            'backslash' => ['/\\evil'],
            'colon scheme smuggle' => ['/foo:bar'],
            'query smuggle' => ['/myforms?x=1'],
            'fragment smuggle' => ['/myforms#x'],
            'no leading slash' => ['myforms'],
            'empty string' => [''],
            'space' => ['/my forms'],
            'non-string int' => [5],
            'non-string null' => [null],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function test_rejects_unsafe_or_malformed_values(mixed $value): void
    {
        $this->assertFalse((new RedirectPathRule())->validate($value));
    }

    public function test_message_names_the_attribute_placeholder(): void
    {
        $this->assertStringContainsString(':attribute', (new RedirectPathRule())->message());
    }
}
