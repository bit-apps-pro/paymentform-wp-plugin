<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Requests\Rules;

use PaymentForm\HTTP\Requests\Rules\UuidRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UuidRuleTest extends TestCase
{
    public static function validUuidProvider(): array
    {
        return [
            'canonical v4' => ['11111111-1111-4111-8111-111111111111'],
            'lowercase' => ['9b2e4c7a-1d3f-4a8b-9c2e-0f1a2b3c4d5e'],
            'uppercase' => ['9B2E4C7A-1D3F-4A8B-9C2E-0F1A2B3C4D5E'],
            'non-v4 version digit' => ['9b2e4c7a-1d3f-1a8b-0c2e-0f1a2b3c4d5e'],
        ];
    }

    #[DataProvider('validUuidProvider')]
    public function test_accepts_canonical_uuids(string $uuid): void
    {
        $this->assertTrue((new UuidRule())->validate($uuid));
    }

    public static function invalidUuidProvider(): array
    {
        return [
            'non-hex word' => ['abc'],
            'numeric id' => ['5'],
            'empty string' => [''],
            'missing hyphens' => ['11111111111141118111111111111111'],
            'wrong group length' => ['1111111-1111-4111-8111-111111111111'],
            'trailing chars' => ['11111111-1111-4111-8111-111111111111x'],
            'non-string int' => [5],
            'non-string null' => [null],
        ];
    }

    #[DataProvider('invalidUuidProvider')]
    public function test_rejects_non_uuids(mixed $value): void
    {
        $this->assertFalse((new UuidRule())->validate($value));
    }

    public function test_message_names_the_attribute_placeholder(): void
    {
        $this->assertSame('The :attribute must be a valid UUID.', (new UuidRule())->message());
    }
}
