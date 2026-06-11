<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Controllers;

use PaymentForm\HTTP\Controllers\SetupController;
use PaymentForm\Services\ApiClient;
use PaymentForm\Services\EncryptionService;
use PaymentForm\Services\TokenRefreshLock;
use PaymentForm\Services\TokenService;
use PaymentForm\Services\TokenServiceInterface;
use PaymentForm\Tests\Support\MockWpRestRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpHttpMock;

/**
 * Characterization tests locking the current JSON contract of
 * SetupController::handle() and ::status() ahead of the routing migration.
 * Every reachable return point is pinned by HTTP status and exact body.
 */
class SetupControllerTest extends TestCase
{
    private const VALID_PARAMS = ['email' => 'admin@example.com', 'country' => 'US'];
    private const PENDING_TRANSIENT_KEY = 'paymentform_setup_pending_1';
    private const EXPIRES_AT = '2026-07-08T09:43:46+00:00';

    private TokenService $tokenService;

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        WpHttpMock::reset();
        $this->tokenService = new TokenService(new EncryptionService());
    }

    private function controller(?TokenServiceInterface $tokenService = null): SetupController
    {
        $tokenService ??= $this->tokenService;

        return new SetupController(
            new ApiClient($tokenService, new TokenRefreshLock(), PAYMENTFORM_API_BASE_URL),
            $tokenService,
        );
    }

    private function handleSetup(
        array $params = self::VALID_PARAMS,
        ?TokenServiceInterface $tokenService = null,
    ): \WP_REST_Response {
        return $this->controller($tokenService)
            ->handle(new MockWpRestRequest('POST', '/paymentform/v1/setup', $params));
    }

    private function pollStatus(?TokenServiceInterface $tokenService = null): \WP_REST_Response
    {
        return $this->controller($tokenService)
            ->status(new MockWpRestRequest('GET', '/paymentform/v1/setup/status'));
    }

    private function stashPendingSetupToken(string $token = 'v1.stashed-token'): void
    {
        set_transient(self::PENDING_TRANSIENT_KEY, $token, 900);
    }

    /** @return array<string, mixed> */
    private function lastRequestJson(): array
    {
        $this->assertNotEmpty(WpHttpMock::$calls, 'Expected an outgoing HTTP request.');
        $call = end(WpHttpMock::$calls);

        return json_decode((string) $call['args']['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    // ---------------------------------------------------------------
    // handle() — input validation (400, no backend call)
    // ---------------------------------------------------------------

    public static function invalidInputProvider(): array
    {
        return [
            'missing email' => [['country' => 'US'], 'Invalid email address.'],
            'malformed email' => [['email' => 'not-an-email', 'country' => 'US'], 'Invalid email address.'],
            'oversized email' => [['email' => str_repeat('a', 250) . '@example.com', 'country' => 'US'], 'Invalid email address.'],
            'missing country' => [['email' => 'admin@example.com'], 'Invalid country code.'],
            'lowercase country' => [['email' => 'admin@example.com', 'country' => 'us'], 'Invalid country code.'],
            'three-letter country' => [['email' => 'admin@example.com', 'country' => 'USA'], 'Invalid country code.'],
            'short locale' => [[...self::VALID_PARAMS, 'locale' => 'en'], 'Invalid locale format.'],
        ];
    }

    #[DataProvider('invalidInputProvider')]
    public function test_handle_rejects_invalid_input(array $params, string $message): void
    {
        $response = $this->handleSetup($params);

        $this->assertSame(400, $response->get_status());
        $this->assertSame(['next' => 'failed', 'code' => 'bad_request', 'message' => $message], $response->get_data());
        $this->assertSame([], WpHttpMock::$calls);
    }

    // ---------------------------------------------------------------
    // handle() — transport and backend error mapping
    // ---------------------------------------------------------------

    public function test_handle_returns_503_network_on_transport_error(): void
    {
        WpHttpMock::queueError('timeout');

        $response = $this->handleSetup();

        $this->assertSame(503, $response->get_status());
        $this->assertSame(
            ['next' => 'failed', 'code' => 'network', 'message' => 'Could not reach paymentform.io. Check your hosting firewall.'],
            $response->get_data()
        );
    }

    public function test_handle_maps_feature_disabled_503_to_service_unavailable(): void
    {
        WpHttpMock::queue(503, ['error_code' => 'FEATURE_DISABLED', 'retry_after' => 120]);

        $response = $this->handleSetup();

        $this->assertSame(503, $response->get_status());
        $this->assertSame(
            [
                'next' => 'failed',
                'code' => 'service_unavailable',
                'message' => 'PaymentForm signup is temporarily unavailable. Please try again later.',
                'retry_after_seconds' => 120,
            ],
            $response->get_data()
        );
    }

    public function test_handle_defaults_feature_disabled_retry_after_to_one_hour(): void
    {
        WpHttpMock::queue(503, ['error_code' => 'FEATURE_DISABLED']);

        $response = $this->handleSetup();

        $this->assertSame(503, $response->get_status());
        $this->assertSame(3600, $response->get_data()['retry_after_seconds']);
    }

    public static function genericBackend503Provider(): array
    {
        return [
            'other error code' => [['error_code' => 'SOMETHING_ELSE']],
            'invalid json' => ['not-json'],
        ];
    }

    #[DataProvider('genericBackend503Provider')]
    public function test_handle_maps_other_503_to_network(array|string $body): void
    {
        WpHttpMock::queue(503, $body);

        $response = $this->handleSetup();

        $this->assertSame(503, $response->get_status());
        $this->assertSame(
            ['next' => 'failed', 'code' => 'network', 'message' => 'Backend service unavailable.'],
            $response->get_data()
        );
    }

    public function test_handle_passes_through_429_with_retry_after_header(): void
    {
        WpHttpMock::queue(429, '', ['retry-after' => '45']);

        $response = $this->handleSetup();

        $this->assertSame(429, $response->get_status());
        $this->assertSame(
            [
                'next' => 'failed',
                'code' => 'rate_limited',
                'message' => 'Too many requests. Please wait a moment before retrying.',
                'retry_after_seconds' => 45,
            ],
            $response->get_data()
        );
    }

    public function test_handle_defaults_429_retry_after_to_zero_without_header(): void
    {
        WpHttpMock::queue(429);

        $response = $this->handleSetup();

        $this->assertSame(429, $response->get_status());
        $this->assertSame(0, $response->get_data()['retry_after_seconds']);
    }

    // ---------------------------------------------------------------
    // handle() — 2xx branches
    // ---------------------------------------------------------------

    public static function malformedSuccessBodyProvider(): array
    {
        return [
            'invalid json' => ['not-json'],
            'missing data envelope' => [['status' => true]],
            'non-array data' => [['data' => 'connected']],
        ];
    }

    #[DataProvider('malformedSuccessBodyProvider')]
    public function test_handle_returns_502_on_malformed_2xx_body(array|string $body): void
    {
        WpHttpMock::queue(200, $body);

        $response = $this->handleSetup();

        $this->assertSame(502, $response->get_status());
        $this->assertSame(
            ['next' => 'failed', 'code' => 'invalid_response', 'message' => 'Invalid backend response.'],
            $response->get_data()
        );
    }

    public function test_handle_connected_stores_token_and_returns_connected(): void
    {
        WpHttpMock::queue(200, ['status' => true, 'data' => [
            'status' => 'connected',
            'token' => 'access-token',
            'expires_at' => self::EXPIRES_AT,
            'webhook_secret' => 'hex-secret',
            'tenant' => 'ws-test',
        ]]);

        $response = $this->handleSetup();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(['next' => 'connected', 'has_workspace' => true], $response->get_data());
        $this->assertSame('access-token', $this->tokenService->get());
        $this->assertSame('hex-secret', $this->tokenService->getWebhookSecret());
        $this->assertSame('ws-test', $this->tokenService->getTenant());
    }

    public function test_handle_connected_returns_500_when_token_storage_fails(): void
    {
        WpHttpMock::queue(200, ['data' => [
            'status' => 'connected',
            'token' => 'access-token',
            'expires_at' => self::EXPIRES_AT,
            'webhook_secret' => 'hex-secret',
        ]]);

        $tokenService = $this->createMock(TokenServiceInterface::class);
        $tokenService->expects($this->once())->method('storeWebhookSecret')->with('hex-secret');
        $tokenService->expects($this->once())->method('store')->willThrowException(new \RuntimeException('disk full'));
        $tokenService->expects($this->once())->method('clear');

        $response = $this->handleSetup(self::VALID_PARAMS, $tokenService);

        $this->assertSame(500, $response->get_status());
        $this->assertSame(
            ['next' => 'failed', 'code' => 'storage_failed', 'message' => 'Failed to store connection.'],
            $response->get_data()
        );
    }

    public function test_handle_connected_without_token_falls_through_to_unknown_error(): void
    {
        WpHttpMock::queue(200, ['data' => ['status' => 'connected']]);

        $response = $this->handleSetup();

        $this->assertSame(500, $response->get_status());
        $this->assertSame(
            ['next' => 'failed', 'code' => 'unknown_error', 'message' => 'An unexpected error occurred.'],
            $response->get_data()
        );
    }

    public function test_handle_check_inbox_returns_masked_email_and_stashes_setup_token(): void
    {
        WpHttpMock::queue(200, ['data' => [
            'status' => 'check_inbox',
            'email_masked' => 'a***@example.com',
            'provisioning' => 'ready',
        ]]);

        $response = $this->handleSetup();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(
            ['next' => 'check_inbox', 'email_masked' => 'a***@example.com', 'provisioning' => 'ready'],
            $response->get_data()
        );

        $stashed = get_transient(self::PENDING_TRANSIENT_KEY);
        $this->assertIsString($stashed);
        $this->assertStringStartsWith('v1.', $stashed);
    }

    public function test_handle_check_inbox_defaults_optional_fields(): void
    {
        WpHttpMock::queue(200, ['data' => ['status' => 'check_inbox']]);

        $response = $this->handleSetup();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(
            ['next' => 'check_inbox', 'email_masked' => null, 'provisioning' => 'pending'],
            $response->get_data()
        );
    }

    public function test_handle_redirects_to_connect_for_existing_account(): void
    {
        WpHttpMock::queue(200, ['data' => [
            'status' => 'use_connect_flow',
            'connect_url' => 'https://app.paymentform.io/connect?x=1',
        ]]);

        $response = $this->handleSetup();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(
            [
                'next' => 'redirect_connect',
                'connect_url' => 'https://app.paymentform.io/connect?x=1',
                'has_workspace' => false,
            ],
            $response->get_data()
        );
    }

    public function test_handle_use_connect_flow_without_url_falls_through_to_unknown_error(): void
    {
        WpHttpMock::queue(200, ['data' => ['status' => 'use_connect_flow']]);

        $response = $this->handleSetup();

        $this->assertSame(500, $response->get_status());
        $this->assertSame('unknown_error', $response->get_data()['code']);
    }

    // ---------------------------------------------------------------
    // handle() — 422 proof errors
    // ---------------------------------------------------------------

    public static function proofSubCodeProvider(): array
    {
        return [
            'proof_dns_failed' => ['proof_dns_failed', "Could not resolve your site's DNS. Confirm your site URL is correct."],
            'proof_tls_failed' => ['proof_tls_failed', "Your site's SSL certificate isn't trusted. Renew it or use HTTP for testing."],
            'proof_unreachable' => ['proof_unreachable', "We couldn't reach your WordPress site. Make sure it's publicly accessible from the internet."],
            'proof_redirect_refused' => ['proof_redirect_refused', 'Your site redirected our verification request. Disable redirect plugins on /paymentform/setup-proof.'],
            'proof_timeout' => ['proof_timeout', 'Your site took too long to respond. Please try again.'],
            'proof_bad_hmac' => ['proof_bad_hmac', 'Verification failed. Your WordPress security keys may have been rotated.'],
            'proof_nonce_mismatch' => ['proof_nonce_mismatch', 'Verification failed. Please try again.'],
            'unknown sub-code' => ['proof_meteor_strike', "We couldn't verify your WordPress site. Please try again."],
        ];
    }

    #[DataProvider('proofSubCodeProvider')]
    public function test_handle_maps_proof_failed_sub_codes(string $subCode, string $message): void
    {
        WpHttpMock::queue(422, ['error_code' => 'PROOF_FAILED', 'errors' => [$subCode]]);

        $response = $this->handleSetup();

        $this->assertSame(422, $response->get_status());
        $this->assertSame(['next' => 'failed', 'code' => $subCode, 'message' => $message], $response->get_data());
    }

    public function test_handle_proof_failed_with_non_array_errors_uses_generic_sub_code(): void
    {
        WpHttpMock::queue(422, ['error_code' => 'PROOF_FAILED', 'errors' => 'oops']);

        $response = $this->handleSetup();

        $this->assertSame(422, $response->get_status());
        $this->assertSame(
            ['next' => 'failed', 'code' => 'proof_failed', 'message' => "We couldn't verify your WordPress site. Please try again."],
            $response->get_data()
        );
    }

    public function test_handle_maps_invalid_proof_host(): void
    {
        WpHttpMock::queue(422, ['error_code' => 'INVALID_PROOF_HOST']);

        $response = $this->handleSetup();

        $this->assertSame(422, $response->get_status());
        $this->assertSame(
            ['next' => 'failed', 'code' => 'invalid_proof_host', 'message' => 'Site URL host mismatch.'],
            $response->get_data()
        );
    }

    public function test_handle_returns_502_on_unparseable_422_body(): void
    {
        WpHttpMock::queue(422, 'not-json');

        $response = $this->handleSetup();

        $this->assertSame(502, $response->get_status());
        $this->assertSame(['next' => 'failed', 'code' => 'invalid_response'], $response->get_data());
    }

    public function test_handle_unrecognized_422_error_code_falls_through_to_unknown_error(): void
    {
        WpHttpMock::queue(422, ['error_code' => 'VALIDATION_FAILED']);

        $response = $this->handleSetup();

        $this->assertSame(500, $response->get_status());
        $this->assertSame('unknown_error', $response->get_data()['code']);
    }

    public function test_handle_returns_500_unknown_error_for_other_backend_statuses(): void
    {
        WpHttpMock::queue(400, ['error_code' => 'WHATEVER']);

        $response = $this->handleSetup();

        $this->assertSame(500, $response->get_status());
        $this->assertSame(
            ['next' => 'failed', 'code' => 'unknown_error', 'message' => 'An unexpected error occurred.'],
            $response->get_data()
        );
    }

    // ---------------------------------------------------------------
    // handle() — outgoing backend request contract
    // ---------------------------------------------------------------

    public function test_handle_sends_setup_token_payload_to_backend(): void
    {
        WpHttpMock::queue(200, ['data' => ['status' => 'check_inbox']]);

        $this->handleSetup();

        $call = end(WpHttpMock::$calls);
        $this->assertSame(PAYMENTFORM_API_BASE_URL . '/auth/setup', $call['url']);
        $this->assertSame('POST', $call['args']['method']);

        $payload = $this->lastRequestJson();
        $this->assertSame('admin@example.com', $payload['email']);
        $this->assertSame('US', $payload['country']);
        $this->assertSame('http://example.test', $payload['site_url']);
        $this->assertSame('http://example.test/paymentform/setup-proof', $payload['proof_url']);
        $this->assertSame('wp-plugin', $payload['client_slug']);
        $this->assertSame('server', $payload['client_type']);
        $this->assertStringStartsWith('v1.', $payload['setup_token']);
        $this->assertArrayNotHasKey('wp_setup_token', $payload);
        $this->assertArrayNotHasKey('locale', $payload);
    }

    public function test_handle_forwards_locale_and_valid_email_override(): void
    {
        WpHttpMock::queue(200, ['data' => ['status' => 'check_inbox']]);

        $this->handleSetup([...self::VALID_PARAMS, 'locale' => 'en_US', 'email_override' => 'owner@example.com']);

        $payload = $this->lastRequestJson();
        $this->assertSame('owner@example.com', $payload['email']);
        $this->assertSame('en_US', $payload['locale']);
    }

    // ---------------------------------------------------------------
    // status() — guard clauses (no backend call)
    // ---------------------------------------------------------------

    public function test_status_returns_401_when_not_logged_in(): void
    {
        \TestWpFunctions::setCurrentUser((object) ['ID' => 0]);

        $response = $this->pollStatus();

        $this->assertSame(401, $response->get_status());
        $this->assertSame(['next' => 'failed', 'code' => 'unauthenticated'], $response->get_data());
        $this->assertSame([], WpHttpMock::$calls);
    }

    public function test_status_returns_410_when_no_pending_setup_token(): void
    {
        $response = $this->pollStatus();

        $this->assertSame(410, $response->get_status());
        $this->assertSame(
            ['next' => 'failed', 'code' => 'setup_expired', 'message' => 'Your setup link has expired. Please start over.'],
            $response->get_data()
        );
        $this->assertSame([], WpHttpMock::$calls);
    }

    // ---------------------------------------------------------------
    // status() — finalize error mapping
    // ---------------------------------------------------------------

    public function test_status_returns_503_network_on_transport_error(): void
    {
        $this->stashPendingSetupToken();
        WpHttpMock::queueError('timeout');

        $response = $this->pollStatus();

        $this->assertSame(503, $response->get_status());
        $this->assertSame(
            ['next' => 'failed', 'code' => 'network', 'message' => 'Could not reach paymentform.io.'],
            $response->get_data()
        );
    }

    public function test_status_maps_backend_410_to_setup_expired_and_drops_transient(): void
    {
        $this->stashPendingSetupToken();
        WpHttpMock::queue(410, ['error_code' => 'SETUP_EXPIRED']);

        $response = $this->pollStatus();

        $this->assertSame(410, $response->get_status());
        $this->assertSame(
            ['next' => 'failed', 'code' => 'setup_expired', 'message' => 'Your setup link has expired. Please start over.'],
            $response->get_data()
        );
        $this->assertFalse(get_transient(self::PENDING_TRANSIENT_KEY));
    }

    public static function finalizeFailureStatusProvider(): array
    {
        return [
            'backend 403' => [403],
            'backend 500' => [500],
        ];
    }

    #[DataProvider('finalizeFailureStatusProvider')]
    public function test_status_passes_through_backend_failure_status(int $statusCode): void
    {
        $this->stashPendingSetupToken();
        WpHttpMock::queue($statusCode, ['error' => 'nope']);

        $response = $this->pollStatus();

        $this->assertSame($statusCode, $response->get_status());
        $this->assertSame(['next' => 'failed', 'code' => 'finalize_failed'], $response->get_data());
    }

    public static function malformedFinalizeBodyProvider(): array
    {
        return [
            'invalid json' => ['not-json'],
            'missing data envelope' => [['status' => true]],
            'non-array data' => [['data' => 'connected']],
            'connected without token' => [['data' => ['status' => 'connected']]],
            'connected with non-string token' => [['data' => ['status' => 'connected', 'token' => 123, 'expires_at' => self::EXPIRES_AT]]],
        ];
    }

    #[DataProvider('malformedFinalizeBodyProvider')]
    public function test_status_returns_502_on_malformed_finalize_body(array|string $body): void
    {
        $this->stashPendingSetupToken();
        WpHttpMock::queue(200, $body);

        $response = $this->pollStatus();

        $this->assertSame(502, $response->get_status());
        $this->assertSame(['next' => 'failed', 'code' => 'invalid_response'], $response->get_data());
    }

    // ---------------------------------------------------------------
    // status() — finalize success paths
    // ---------------------------------------------------------------

    public function test_status_connected_returns_500_when_token_storage_fails(): void
    {
        $this->stashPendingSetupToken();
        WpHttpMock::queue(200, ['data' => [
            'status' => 'connected',
            'token' => 'access-token',
            'expires_at' => self::EXPIRES_AT,
            'webhook_secret' => 'hex-secret',
        ]]);

        $tokenService = $this->createMock(TokenServiceInterface::class);
        $tokenService->expects($this->once())->method('storeWebhookSecret')->with('hex-secret');
        $tokenService->expects($this->once())->method('store')->willThrowException(new \RuntimeException('disk full'));
        $tokenService->expects($this->once())->method('clear');

        $response = $this->pollStatus($tokenService);

        $this->assertSame(500, $response->get_status());
        $this->assertSame(['next' => 'failed', 'code' => 'storage_failed'], $response->get_data());
    }

    public function test_status_connected_stores_token_and_consumes_transient(): void
    {
        $this->stashPendingSetupToken();
        WpHttpMock::queue(200, ['data' => [
            'status' => 'connected',
            'token' => 'access-token',
            'expires_at' => self::EXPIRES_AT,
            'webhook_secret' => 'hex-secret',
            'tenant' => 'ws-test',
        ]]);

        $response = $this->pollStatus();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(
            ['next' => 'connected', 'has_workspace' => true, 'email_verified' => true],
            $response->get_data()
        );
        $this->assertSame('access-token', $this->tokenService->get());
        $this->assertSame('hex-secret', $this->tokenService->getWebhookSecret());
        $this->assertSame('ws-test', $this->tokenService->getTenant());
        $this->assertFalse(get_transient(self::PENDING_TRANSIENT_KEY));
    }

    public static function pendingFinalizeProvider(): array
    {
        return [
            'explicit fields' => [
                ['data' => ['status' => 'pending', 'email_verified' => true, 'provisioning' => 'ready']],
                ['next' => 'check_inbox', 'email_verified' => true, 'provisioning' => 'ready'],
            ],
            'defaults when fields absent' => [
                ['data' => []],
                ['next' => 'check_inbox', 'email_verified' => false, 'provisioning' => 'pending'],
            ],
        ];
    }

    #[DataProvider('pendingFinalizeProvider')]
    public function test_status_reports_check_inbox_while_pending(array $body, array $expected): void
    {
        $this->stashPendingSetupToken();
        WpHttpMock::queue(200, $body);

        $response = $this->pollStatus();

        $this->assertSame(200, $response->get_status());
        $this->assertSame($expected, $response->get_data());
    }

    // ---------------------------------------------------------------
    // status() — outgoing finalize request contract
    // ---------------------------------------------------------------

    public function test_status_sends_setup_token_to_finalize_endpoint(): void
    {
        $this->stashPendingSetupToken('v1.stashed-token');
        WpHttpMock::queue(200, ['data' => []]);

        $this->pollStatus();

        $call = end(WpHttpMock::$calls);
        $this->assertSame(PAYMENTFORM_API_BASE_URL . '/auth/setup/finalize', $call['url']);
        $this->assertSame('POST', $call['args']['method']);
        $this->assertSame(['setup_token' => 'v1.stashed-token'], $this->lastRequestJson());
    }
}
