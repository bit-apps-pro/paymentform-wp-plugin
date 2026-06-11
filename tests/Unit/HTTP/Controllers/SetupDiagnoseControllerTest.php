<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Controllers;

use PaymentForm\HTTP\Controllers\SetupDiagnoseController;
use PaymentForm\Tests\Support\MockWpRestRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpHttpMock;

/**
 * Characterization tests locking the current JSON contract of
 * SetupDiagnoseController::handle() ahead of the routing migration.
 * The endpoint is soft-pass by design: every return is HTTP 200, and only
 * an explicit backend failure verdict surfaces as { next: 'failed' }.
 */
class SetupDiagnoseControllerTest extends TestCase
{
    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        WpHttpMock::reset();
    }

    private function diagnose(): \WP_REST_Response
    {
        return (new SetupDiagnoseController())
            ->handle(new MockWpRestRequest('POST', '/paymentform/v1/setup/diagnose'));
    }

    public static function softPassProvider(): array
    {
        return [
            'backend endpoint missing (404)' => [404, ''],
            'backend ok verdict' => [200, ['next' => 'ok']],
            '2xx with invalid json' => [200, 'not-json'],
            '2xx without verdict' => [200, ['status' => true]],
            'backend 500' => [500, ['error' => 'boom']],
        ];
    }

    #[DataProvider('softPassProvider')]
    public function test_handle_soft_passes(int $status, array|string $body): void
    {
        WpHttpMock::queue($status, $body);

        $response = $this->diagnose();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(['next' => 'ok'], $response->get_data());
    }

    public function test_handle_soft_passes_on_transport_error(): void
    {
        WpHttpMock::queueError('timeout');

        $response = $this->diagnose();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(['next' => 'ok'], $response->get_data());
    }

    public function test_handle_surfaces_backend_failure_verdict_with_200(): void
    {
        WpHttpMock::queue(200, ['next' => 'failed', 'code' => 'proof_unreachable', 'message' => 'Site not reachable.']);

        $response = $this->diagnose();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(
            ['next' => 'failed', 'code' => 'proof_unreachable', 'message' => 'Site not reachable.'],
            $response->get_data()
        );
    }

    public function test_handle_defaults_failure_verdict_fields(): void
    {
        WpHttpMock::queue(200, ['next' => 'failed']);

        $response = $this->diagnose();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(
            ['next' => 'failed', 'code' => 'unknown', 'message' => 'Diagnosis failed.'],
            $response->get_data()
        );
    }

    public function test_handle_sends_setup_token_payload_to_diagnose_endpoint(): void
    {
        WpHttpMock::queue(200, ['next' => 'ok']);

        $this->diagnose();

        $call = end(WpHttpMock::$calls);
        $this->assertSame(PAYMENTFORM_API_BASE_URL . '/auth/setup/diagnose', $call['url']);
        $this->assertSame('POST', $call['args']['method']);

        $payload = json_decode((string) $call['args']['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('http://example.test/paymentform/setup-proof', $payload['proof_url']);
        $this->assertStringStartsWith('v1.', $payload['setup_token']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $payload['nonce']);
        $this->assertArrayNotHasKey('wp_setup_token', $payload);
    }
}
