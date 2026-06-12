<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Controllers;

use PaymentForm\HTTP\Controllers\SetupDiagnoseController;
use PaymentForm\Providers\ServiceContainer;
use PaymentForm\Services\Http\HttpTransport;
use PaymentForm\Services\SetupApiClient;
use PaymentForm\Tests\Support\InteractsWithWpKit;
use PaymentForm\Tests\Support\SeedsServiceContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpHttpMock;

/**
 * Characterization tests locking the JSON contract of
 * SetupDiagnoseController::handle() across the routing migration.
 * The endpoint is soft-pass by design: every return is a bare array (HTTP
 * 200 via the wp-kit success envelope), and only an explicit backend
 * failure verdict surfaces as { next: 'failed' }.
 */
class SetupDiagnoseControllerTest extends TestCase
{
    use InteractsWithWpKit;
    use SeedsServiceContainer;

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        WpHttpMock::reset();
        $this->seedContainer([
            SetupApiClient::class => new SetupApiClient(new HttpTransport(PAYMENTFORM_API_BASE_URL)),
        ]);
    }

    protected function tearDown(): void
    {
        ServiceContainer::reset();
    }

    /** @return array<string, mixed> */
    private function diagnose(): array
    {
        return (new SetupDiagnoseController())
            ->handle($this->makeWpKitRequest(method: 'POST'));
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

        $this->assertSame(['next' => 'ok'], $this->diagnose());
    }

    public function test_handle_soft_passes_on_transport_error(): void
    {
        WpHttpMock::queueError('timeout');

        $this->assertSame(['next' => 'ok'], $this->diagnose());
    }

    public function test_handle_surfaces_backend_failure_verdict_with_200(): void
    {
        WpHttpMock::queue(200, ['next' => 'failed', 'code' => 'proof_unreachable', 'message' => 'Site not reachable.']);

        $this->assertSame(
            ['next' => 'failed', 'code' => 'proof_unreachable', 'message' => 'Site not reachable.'],
            $this->diagnose()
        );
    }

    public function test_handle_defaults_failure_verdict_fields(): void
    {
        WpHttpMock::queue(200, ['next' => 'failed']);

        $this->assertSame(
            ['next' => 'failed', 'code' => 'unknown', 'message' => 'Diagnosis failed.'],
            $this->diagnose()
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
        $this->assertSame('http://example.test', $payload['site_url']);
        $this->assertSame('wp-plugin', $payload['client_slug']);
        $this->assertStringStartsWith('v1.', $payload['setup_token']);
        $this->assertArrayNotHasKey('nonce', $payload);
        $this->assertArrayNotHasKey('wp_setup_token', $payload);
    }
}
