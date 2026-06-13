<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Requests;

use PaymentForm\HTTP\Requests\FormShowRequest;
use PaymentForm\HTTP\Requests\Rules\UuidRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Drives FormShowRequest::rules() through wp-validator make() — replacing the
 * (buggy, numeric) id guard that was removed from FormsProxyController. Form
 * ids are UUIDs; a numeric id must now FAIL.
 */
class FormShowRequestTest extends TestCase
{
    public function test_rules_shape(): void
    {
        $this->assertSame(['id' => ['required', UuidRule::class]], (new FormShowRequest())->rules());
    }

    public function test_passes_with_canonical_uuid(): void
    {
        $request = new FormShowRequest();
        $data = ['id' => '11111111-1111-4111-8111-111111111111'];

        $this->assertFalse($request->make($data, $request->rules())->fails());
    }

    public static function invalidProvider(): array
    {
        return [
            'missing' => [[]],
            'empty' => [['id' => '']],
            'non-uuid word' => [['id' => 'abc']],
            'numeric id' => [['id' => '5']],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function test_fails_on_invalid_id(array $data): void
    {
        $request = new FormShowRequest();

        $this->assertTrue($request->make($data, $request->rules())->fails());
    }
}
