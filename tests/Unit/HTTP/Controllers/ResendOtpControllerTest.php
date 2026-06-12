<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Controllers;

use PaymentForm\Deps\BitApps\WPKit\Http\Response;
use PaymentForm\HTTP\Controllers\ResendOtpController;
use PaymentForm\Providers\ServiceContainer;
use PaymentForm\Services\Http\HttpTransport;
use PaymentForm\Services\SetupApiClient;
use PaymentForm\Tests\Support\InteractsWithWpKit;
use PaymentForm\Tests\Support\SeedsServiceContainer;
use PHPUnit\Framework\TestCase;
use WpHttpMock;

/**
 * Pins the JSON contract of ResendOtpController::handle(): guards locally
 * (no-auth / no transient → no backend call), bridges the stashed
 * wp_setup_token to backend /auth/setup/resend-otp, and maps the response —
 * 2xx returns a bare `{ ok, resend_after_seconds }`, failures ride the wp-kit
 * error envelope so the SPA shows "Could not resend".
 */
class ResendOtpControllerTest extends TestCase
{
    use InteractsWithWpKit;
    use SeedsServiceContainer;

    private const PENDING_TRANSIENT_KEY = 'paymentform_setup_pending_1';

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        WpHttpMock::reset();
        $this->resetWpKitResponse();
    }

    protected function tearDown(): void
    {
        ServiceContainer::reset();
        $this->resetWpKitResponse();
    }

    private function controller(): ResendOtpController
    {
        $this->seedContainer([
            SetupApiClient::class => new SetupApiClient(new HttpTransport(PAYMENTFORM_API_BASE_URL)),
        ]);

        return new ResendOtpController();
    }

    private function resend(): array|Response
    {
        return $this->controller()->handle($this->makeWpKitRequest(method: 'POST'));
    }

    private function stashPendingSetupToken(string $token = 'v1.stashed-token'): void
    {
        set_transient(self::PENDING_TRANSIENT_KEY, $token, 900);
    }

    /** @param array<string, mixed> $body */
    private function assertFailure(mixed $result, int $httpStatus, array $body, string $code): void
    {
        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame('error', Response::getStatus());
        $this->assertSame($httpStatus, Response::getHttpStatusCode());
        $this->assertSame($body, Response::getData());
        $this->assertSame($code, Response::getCode());
    }

    // --- local guards (no backend call) ---------------------------------

    public function test_returns_401_when_not_logged_in(): void
    {
        \TestWpFunctions::setCurrentUser((object) ['ID' => 0]);

        $result = $this->resend();

        $this->assertFailure($result, 401, ['ok' => false], 'unauthenticated');
        $this->assertSame([], WpHttpMock::$calls);
    }

    public function test_returns_410_when_no_pending_transient_without_backend_call(): void
    {
        $result = $this->resend();

        $this->assertFailure($result, 410, ['ok' => false], 'setup_expired');
        $this->assertSame([], WpHttpMock::$calls);
    }

    // --- outgoing request contract --------------------------------------

    public function test_bridges_token_to_backend(): void
    {
        $this->stashPendingSetupToken('v1.stashed-token');
        WpHttpMock::queue(200, ['data' => ['ok' => true, 'resend_after_seconds' => 60]]);

        $this->resend();

        $call = end(WpHttpMock::$calls);
        $this->assertSame(PAYMENTFORM_API_BASE_URL . '/auth/setup/resend-otp', $call['url']);
        $this->assertSame('POST', $call['args']['method']);
        $this->assertSame(
            ['setup_token' => 'v1.stashed-token'],
            json_decode((string) $call['args']['body'], true, 512, JSON_THROW_ON_ERROR)
        );
    }

    // --- 2xx success ----------------------------------------------------

    public function test_maps_2xx_to_bare_ok_with_cooldown(): void
    {
        $this->stashPendingSetupToken();
        WpHttpMock::queue(200, ['data' => ['ok' => true, 'resend_after_seconds' => 60]]);

        $result = $this->resend();

        $this->assertSame(['ok' => true, 'resend_after_seconds' => 60], $result);
    }

    public function test_defaults_resend_after_seconds_when_absent(): void
    {
        $this->stashPendingSetupToken();
        WpHttpMock::queue(200, ['data' => ['ok' => true]]);

        $result = $this->resend();

        $this->assertSame(['ok' => true, 'resend_after_seconds' => 60], $result);
    }

    // --- structural failures (wp-kit error envelope) --------------------

    public function test_maps_backend_410_to_setup_expired_and_drops_transient(): void
    {
        $this->stashPendingSetupToken();
        WpHttpMock::queue(410, ['error_code' => 'INVALID_STATE']);

        $result = $this->resend();

        $this->assertFailure($result, 410, ['ok' => false], 'setup_expired');
        $this->assertFalse(get_transient(self::PENDING_TRANSIENT_KEY));
    }

    public function test_maps_transport_error_to_503_network(): void
    {
        $this->stashPendingSetupToken();
        WpHttpMock::queueError('timeout');

        $result = $this->resend();

        $this->assertFailure($result, 503, ['ok' => false], 'network');
    }

    public function test_maps_feature_disabled_503_to_service_unavailable(): void
    {
        $this->stashPendingSetupToken();
        WpHttpMock::queue(503, ['error_code' => 'FEATURE_DISABLED']);

        $result = $this->resend();

        $this->assertFailure($result, 503, ['ok' => false], 'service_unavailable');
    }

    public function test_maps_malformed_2xx_body_to_502(): void
    {
        $this->stashPendingSetupToken();
        WpHttpMock::queue(200, ['status' => true]); // missing data envelope

        $result = $this->resend();

        $this->assertFailure($result, 502, ['ok' => false], 'invalid_response');
    }
}
