<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Controllers;

use PaymentForm\Deps\BitApps\WPKit\Http\Response;
use PaymentForm\HTTP\Controllers\VerifyOtpController;
use PaymentForm\Providers\ServiceContainer;
use PaymentForm\Services\Http\HttpTransport;
use PaymentForm\Services\SetupApiClient;
use PaymentForm\Tests\Support\InteractsWithWpKit;
use PaymentForm\Tests\Support\SeedsServiceContainer;
use PHPUnit\Framework\TestCase;
use WpHttpMock;

/**
 * Pins the JSON contract of VerifyOtpController::handle(): it guards locally
 * (no-auth, non-6-digit, no transient → no backend call), bridges {otp, token}
 * to backend /auth/setup/verify-otp, and maps the deferred-token verify
 * response per the OTP spec table — wrong/expended codes ride a 2xx with a
 * human message, only structural failures go through the wp-kit error envelope.
 */
class VerifyOtpControllerTest extends TestCase
{
    use InteractsWithWpKit;
    use SeedsServiceContainer;

    private const PENDING_TRANSIENT_KEY = 'paymentform_setup_pending_1';
    private const VALID_OTP = '123456';

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

    private function controller(): VerifyOtpController
    {
        $this->seedContainer([
            SetupApiClient::class => new SetupApiClient(new HttpTransport(PAYMENTFORM_API_BASE_URL)),
        ]);

        return new VerifyOtpController();
    }

    private function verify(string $otp = self::VALID_OTP): array|Response
    {
        return $this->controller()
            ->handle($this->makeWpKitRequest(params: ['otp' => $otp], method: 'POST'));
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

        $result = $this->verify();

        $this->assertFailure($result, 401, ['verified' => false, 'error' => 'unauthenticated'], 'unauthenticated');
        $this->assertSame([], WpHttpMock::$calls);
    }

    public function test_rejects_non_six_digit_code_without_backend_call(): void
    {
        $this->stashPendingSetupToken();

        $result = $this->verify('abc');

        $this->assertFailure($result, 400, ['verified' => false, 'error' => 'bad_request'], 'bad_request');
        $this->assertSame([], WpHttpMock::$calls);
    }

    public function test_returns_410_when_no_pending_transient_without_backend_call(): void
    {
        $result = $this->verify();

        $this->assertFailure($result, 410, ['verified' => false, 'error' => 'setup_expired'], 'setup_expired');
        $this->assertSame([], WpHttpMock::$calls);
    }

    // --- outgoing request contract --------------------------------------

    public function test_bridges_otp_and_token_to_backend(): void
    {
        $this->stashPendingSetupToken('v1.stashed-token');
        WpHttpMock::queue(200, ['data' => ['verified' => true]]);

        $this->verify();

        $call = end(WpHttpMock::$calls);
        $this->assertSame(PAYMENTFORM_API_BASE_URL . '/auth/setup/verify-otp', $call['url']);
        $this->assertSame('POST', $call['args']['method']);
        $this->assertSame(
            ['setup_token' => 'v1.stashed-token', 'code' => self::VALID_OTP],
            json_decode((string) $call['args']['body'], true, 512, JSON_THROW_ON_ERROR)
        );
    }

    // --- 2xx verify outcomes (bare arrays) ------------------------------

    public function test_maps_verified_true_to_bare_array(): void
    {
        $this->stashPendingSetupToken();
        WpHttpMock::queue(200, ['data' => ['verified' => true]]);

        $result = $this->verify();

        $this->assertSame(['verified' => true], $result);
    }

    public function test_maps_wrong_code_to_attempts_message_on_200(): void
    {
        $this->stashPendingSetupToken();
        WpHttpMock::queue(200, ['data' => ['verified' => false, 'attempts_remaining' => 4]]);

        $result = $this->verify();

        $this->assertSame(
            ['verified' => false, 'error' => 'Invalid code. 4 attempts remaining.'],
            $result
        );
    }

    public function test_maps_exhausted_attempts_to_terminal_message_on_200(): void
    {
        $this->stashPendingSetupToken();
        WpHttpMock::queue(200, ['data' => ['verified' => false, 'attempts_remaining' => 0]]);

        $result = $this->verify();

        $this->assertSame(
            ['verified' => false, 'error' => 'Too many failed attempts. Request a new code.'],
            $result
        );
    }

    // --- structural failures (wp-kit error envelope) --------------------

    public function test_maps_backend_410_to_setup_expired_and_drops_transient(): void
    {
        $this->stashPendingSetupToken();
        WpHttpMock::queue(410, ['error_code' => 'INVALID_STATE']);

        $result = $this->verify();

        $this->assertFailure($result, 410, ['verified' => false, 'error' => 'setup_expired'], 'setup_expired');
        $this->assertFalse(get_transient(self::PENDING_TRANSIENT_KEY));
    }

    public function test_maps_transport_error_to_503_network(): void
    {
        $this->stashPendingSetupToken();
        WpHttpMock::queueError('timeout');

        $result = $this->verify();

        $this->assertFailure($result, 503, ['verified' => false, 'error' => 'network'], 'network');
    }

    public function test_maps_feature_disabled_503_to_service_unavailable(): void
    {
        $this->stashPendingSetupToken();
        WpHttpMock::queue(503, ['error_code' => 'FEATURE_DISABLED']);

        $result = $this->verify();

        $this->assertFailure($result, 503, ['verified' => false, 'error' => 'service_unavailable'], 'service_unavailable');
    }

    public function test_passes_through_other_backend_failure_status(): void
    {
        $this->stashPendingSetupToken();
        WpHttpMock::queue(403, ['error_code' => 'NOPE']);

        $result = $this->verify();

        $this->assertFailure($result, 403, ['verified' => false, 'error' => 'verify_failed'], 'verify_failed');
    }

    public function test_maps_malformed_2xx_body_to_502(): void
    {
        $this->stashPendingSetupToken();
        WpHttpMock::queue(200, ['status' => true]); // missing data envelope

        $result = $this->verify();

        $this->assertFailure($result, 502, ['verified' => false, 'error' => 'invalid_response'], 'invalid_response');
    }

    public function test_maps_unparseable_2xx_body_to_502(): void
    {
        $this->stashPendingSetupToken();
        WpHttpMock::queue(200, 'not-json');

        $result = $this->verify();

        $this->assertFailure($result, 502, ['verified' => false, 'error' => 'invalid_response'], 'invalid_response');
    }
}
