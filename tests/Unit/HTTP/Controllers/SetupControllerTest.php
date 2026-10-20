<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Controllers;

use PaymentForm\Config;
use PaymentForm\Deps\BitApps\WPKit\Http\Response;
use PaymentForm\HTTP\Controllers\SetupController;
use PaymentForm\HTTP\Requests\SetupRequest;
use PaymentForm\Providers\ServiceContainer;
use PaymentForm\Services\ConnectService;
use PaymentForm\Services\EncryptionService;
use PaymentForm\Services\Http\HttpTransport;
use PaymentForm\Services\SetupApiClient;
use PaymentForm\Services\TokenService;
use PaymentForm\Services\TokenServiceInterface;
use PaymentForm\Tests\Support\InteractsWithWpKit;
use PaymentForm\Tests\Support\IssuesConnectState;
use PaymentForm\Tests\Support\SeedsServiceContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpHttpMock;

/**
 * Characterization tests locking the JSON contract of
 * SetupController::handle() and ::status() across the routing migration.
 * Every reachable return point is pinned per the migration-spec mapping
 * table (#1-15 / S1-S10): 2xx branches return bare arrays; non-2xx branches
 * populate the wp-kit Response singleton with the full legacy body under
 * data and the body's code/message mirrored onto the envelope.
 */
class SetupControllerTest extends TestCase
{
    use InteractsWithWpKit;
    use IssuesConnectState;
    use SeedsServiceContainer;

    private const VALID_PARAMS = ['email' => 'admin@example.com', 'country' => 'US'];
    private const PENDING_TRANSIENT_KEY = 'paymentform_setup_pending_1';
    private const EXPIRES_AT = '2026-07-08T09:43:46+00:00';
    private const SETUP_OPTION_PREFIX = 'paymentform_setup_';

    private TokenService $tokenService;

    private ConnectService $connectService;

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        WpHttpMock::reset();
        $this->resetWpKitResponse();
        $this->tokenService = new TokenService(new EncryptionService());
        $this->connectService = new ConnectService();
    }

    protected function tearDown(): void
    {
        ServiceContainer::reset();
        $this->resetWpKitResponse();
    }

    private function controller(?TokenServiceInterface $tokenService = null): SetupController
    {
        $tokenService ??= $this->tokenService;

        $this->seedContainer([
            SetupApiClient::class => new SetupApiClient(new HttpTransport(Config::apiBaseUrl())),
            TokenService::class => $tokenService,
            ConnectService::class => $this->connectService,
        ]);

        return new SetupController();
    }

    private function handleSetup(
        array $params = self::VALID_PARAMS,
        ?TokenServiceInterface $tokenService = null,
    ): array|Response {
        return $this->controller($tokenService)
            ->handle($this->makeWpKitRequest(params: $params, method: 'POST', requestClass: SetupRequest::class));
    }

    private function pollStatus(?TokenServiceInterface $tokenService = null): array|Response
    {
        return $this->controller($tokenService)
            ->status($this->makeWpKitRequest());
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

    /**
     * Asserts a non-2xx return per the mapping-table convention: the full
     * legacy body under data, HTTP status preserved, and the body's
     * code/message mirrored onto the wp-kit envelope.
     *
     * @param array<string, mixed> $body
     */
    private function assertFailure(mixed $result, int $httpStatus, array $body): void
    {
        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame('error', Response::getStatus());
        $this->assertSame($httpStatus, Response::getHttpStatusCode());
        $this->assertSame($body, Response::getData());
        $this->assertSame($body['code'], Response::getCode());
        $this->assertSame($body['message'] ?? null, Response::getMessage());
    }

    // ---------------------------------------------------------------
    // handle() — input validation moved to SetupRequest::rules(), which the
    // wp-kit router runs before the action. These unit tests call the action
    // directly (bypassing the router), so malformed-input cases now live in
    // SetupRequestTest; only well-formed input reaches handle() here.
    // ---------------------------------------------------------------

    // ---------------------------------------------------------------
    // handle() — transport and backend error mapping — #4-#7
    // ---------------------------------------------------------------

    public function test_handle_returns_503_network_on_transport_error(): void
    {
        WpHttpMock::queueError('timeout');

        $result = $this->handleSetup();

        $this->assertFailure($result, 503, [
            'next' => 'failed',
            'code' => 'network',
            'message' => 'Could not reach paymentform.io. Check your hosting firewall.',
        ]);
    }

    public function test_handle_maps_feature_disabled_503_to_service_unavailable(): void
    {
        WpHttpMock::queue(503, ['error_code' => 'FEATURE_DISABLED', 'retry_after' => 120]);

        $result = $this->handleSetup();

        $this->assertFailure($result, 503, [
            'next' => 'failed',
            'code' => 'service_unavailable',
            'message' => 'PaymentForm signup is temporarily unavailable. Please try again later.',
            'retry_after_seconds' => 120,
        ]);
    }

    public function test_handle_defaults_feature_disabled_retry_after_to_one_hour(): void
    {
        WpHttpMock::queue(503, ['error_code' => 'FEATURE_DISABLED']);

        $result = $this->handleSetup();

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(503, Response::getHttpStatusCode());
        $this->assertSame(3600, Response::getData()['retry_after_seconds']);
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

        $result = $this->handleSetup();

        $this->assertFailure($result, 503, [
            'next' => 'failed',
            'code' => 'network',
            'message' => 'Backend service unavailable.',
        ]);
    }

    public function test_handle_passes_through_429_with_retry_after_header(): void
    {
        WpHttpMock::queue(429, '', ['retry-after' => '45']);

        $result = $this->handleSetup();

        $this->assertFailure($result, 429, [
            'next' => 'failed',
            'code' => 'rate_limited',
            'message' => 'Too many requests. Please wait a moment before retrying.',
            'retry_after_seconds' => 45,
        ]);
    }

    public function test_handle_defaults_429_retry_after_to_zero_without_header(): void
    {
        WpHttpMock::queue(429);

        $result = $this->handleSetup();

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(429, Response::getHttpStatusCode());
        $this->assertSame(0, Response::getData()['retry_after_seconds']);
    }

    // ---------------------------------------------------------------
    // handle() — 2xx branches — #8-#12
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

        $result = $this->handleSetup();

        $this->assertFailure($result, 502, [
            'next' => 'failed',
            'code' => 'invalid_response',
            'message' => 'Invalid backend response.',
        ]);
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

        $result = $this->handleSetup();

        $this->assertSame(['next' => 'connected', 'has_workspace' => true], $result);
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

        $result = $this->handleSetup(self::VALID_PARAMS, $tokenService);

        $this->assertFailure($result, 500, [
            'next' => 'failed',
            'code' => 'storage_failed',
            'message' => 'Failed to store connection.',
        ]);
    }

    public function test_handle_connected_without_token_falls_through_to_unknown_error(): void
    {
        WpHttpMock::queue(200, ['data' => ['status' => 'connected']]);

        $result = $this->handleSetup();

        $this->assertFailure($result, 500, [
            'next' => 'failed',
            'code' => 'unknown_error',
            'message' => 'An unexpected error occurred.',
        ]);
    }

    public function test_handle_check_inbox_returns_masked_email_and_stashes_setup_token(): void
    {
        WpHttpMock::queue(200, ['data' => [
            'status' => 'check_inbox',
            'email_masked' => 'a***@example.com',
            'provisioning' => 'ready',
        ]]);

        $result = $this->handleSetup();

        $this->assertSame(
            ['next' => 'check_inbox', 'email_masked' => 'a***@example.com', 'provisioning' => 'ready'],
            $result
        );

        $stashed = get_transient(self::PENDING_TRANSIENT_KEY);
        $this->assertIsString($stashed);
        $this->assertStringStartsWith('v1.', $stashed);
    }

    public function test_handle_check_inbox_defaults_optional_fields(): void
    {
        WpHttpMock::queue(200, ['data' => ['status' => 'check_inbox']]);

        $result = $this->handleSetup();

        $this->assertSame(
            ['next' => 'check_inbox', 'email_masked' => null, 'provisioning' => 'pending'],
            $result
        );
    }

    public function test_handle_redirects_to_locally_issued_connect_url_for_existing_account(): void
    {
        WpHttpMock::queue(200, ['data' => ['status' => 'use_connect_flow']]);

        $result = $this->handleSetup();

        $this->assertSame('redirect_connect', $result['next']);
        $this->assertFalse($result['has_workspace']);
        $this->assertStringStartsWith(Config::appBaseUrl() . '/connect?', $result['connect_url']);

        $params = $this->connectUrlParams($result['connect_url']);
        $this->assertSame('http://example.test/paymentform/callback', $params['callback_url']);
        $this->assertSame(1, $this->connectService->consumeState($params['state']));
    }

    public function test_handle_use_connect_flow_returns_storage_failed_when_state_cannot_be_persisted(): void
    {
        WpHttpMock::queue(200, ['data' => ['status' => 'use_connect_flow']]);
        $GLOBALS['wpdb']->insertFails = true;

        $result = $this->handleSetup();

        $this->assertFailure($result, 500, [
            'next' => 'failed',
            'code' => 'storage_failed',
            'message' => 'Failed to start the connect flow.',
        ]);
    }

    public function test_handle_ignores_backend_supplied_connect_url(): void
    {
        $backendUrl = 'https://app.paymentform.io/connect?x=1';
        WpHttpMock::queue(200, ['data' => ['status' => 'use_connect_flow', 'connect_url' => $backendUrl]]);

        $result = $this->handleSetup();

        $this->assertNotSame($backendUrl, $result['connect_url']);
        $this->assertArrayHasKey('state', $this->connectUrlParams($result['connect_url']));
    }

    // ---------------------------------------------------------------
    // handle() — setup option row lifecycle
    // ---------------------------------------------------------------

    public static function setupOutcomeProvider(): array
    {
        return [
            'transport error' => [null],
            'connected' => [[200, ['data' => [
                'status' => 'connected',
                'token' => 'access-token',
                'expires_at' => self::EXPIRES_AT,
            ]]]],
            'check_inbox' => [[200, ['data' => ['status' => 'check_inbox']]]],
            'use_connect_flow' => [[200, ['data' => ['status' => 'use_connect_flow']]]],
            'proof failed' => [[422, ['error_code' => 'PROOF_FAILED', 'errors' => ['proof_timeout']]]],
            'feature disabled' => [[503, ['error_code' => 'FEATURE_DISABLED']]],
            'unexpected status' => [[500, ['error' => 'boom']]],
        ];
    }

    /** @param array{0: int, 1: array<string, mixed>}|null $response null queues a transport error */
    #[DataProvider('setupOutcomeProvider')]
    public function test_handle_keeps_setup_row_during_backend_call_and_deletes_it_after(?array $response): void
    {
        $response === null ? WpHttpMock::queueError('timeout') : WpHttpMock::queue(...$response);

        $this->handleSetup();

        $atCallTime = $this->setupOptionNames(end(WpHttpMock::$calls)['option_names']);
        $this->assertCount(1, $atCallTime);
        $this->assertStringStartsWith(self::SETUP_OPTION_PREFIX . 'by_nonce_', $atCallTime[0]);
        $this->assertSame([], $this->setupOptionNames(array_keys(\TestWpFunctions::optionRows())));
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function setupOptionNames(array $names): array
    {
        return array_values(array_filter(
            $names,
            static fn (string $name): bool => str_starts_with($name, self::SETUP_OPTION_PREFIX)
        ));
    }

    // ---------------------------------------------------------------
    // handle() — 422 proof errors — #13-#14
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

        $result = $this->handleSetup();

        $this->assertFailure($result, 422, ['next' => 'failed', 'code' => $subCode, 'message' => $message]);
    }

    public static function degenerateProofErrorsProvider(): array
    {
        return [
            // current([]) === false used to slip past the `?? 'proof_failed'`
            // null-only fallback and crash mapProofErrorMessage() under
            // strict_types; these rows lock the fixed default sub-code.
            'empty errors array' => [['error_code' => 'PROOF_FAILED', 'errors' => []]],
            'missing errors key' => [['error_code' => 'PROOF_FAILED']],
            'non-array errors' => [['error_code' => 'PROOF_FAILED', 'errors' => 'oops']],
            'non-string first error' => [['error_code' => 'PROOF_FAILED', 'errors' => [['nested' => 'array']]]],
        ];
    }

    #[DataProvider('degenerateProofErrorsProvider')]
    public function test_handle_proof_failed_with_degenerate_errors_uses_generic_sub_code(array $body): void
    {
        WpHttpMock::queue(422, $body);

        $result = $this->handleSetup();

        $this->assertFailure($result, 422, [
            'next' => 'failed',
            'code' => 'proof_failed',
            'message' => "We couldn't verify your WordPress site. Please try again.",
        ]);
    }

    public function test_handle_maps_invalid_proof_host(): void
    {
        WpHttpMock::queue(422, ['error_code' => 'INVALID_PROOF_HOST']);

        $result = $this->handleSetup();

        $this->assertFailure($result, 422, [
            'next' => 'failed',
            'code' => 'invalid_proof_host',
            'message' => 'Site URL host mismatch.',
        ]);
    }

    public function test_handle_returns_502_on_unparseable_422_body(): void
    {
        WpHttpMock::queue(422, 'not-json');

        $result = $this->handleSetup();

        $this->assertFailure($result, 502, ['next' => 'failed', 'code' => 'invalid_response']);
    }

    // ---------------------------------------------------------------
    // handle() — generic fallthrough — #15
    // ---------------------------------------------------------------

    public function test_handle_unrecognized_422_error_code_falls_through_to_unknown_error(): void
    {
        WpHttpMock::queue(422, ['error_code' => 'VALIDATION_FAILED']);

        $result = $this->handleSetup();

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(500, Response::getHttpStatusCode());
        $this->assertSame('unknown_error', Response::getCode());
    }

    public function test_handle_returns_500_unknown_error_for_other_backend_statuses(): void
    {
        WpHttpMock::queue(400, ['error_code' => 'WHATEVER']);

        $result = $this->handleSetup();

        $this->assertFailure($result, 500, [
            'next' => 'failed',
            'code' => 'unknown_error',
            'message' => 'An unexpected error occurred.',
        ]);
    }

    // ---------------------------------------------------------------
    // handle() — outgoing backend request contract
    // ---------------------------------------------------------------

    public function test_handle_sends_setup_token_payload_to_backend(): void
    {
        WpHttpMock::queue(200, ['data' => ['status' => 'check_inbox']]);

        $this->handleSetup();

        $call = end(WpHttpMock::$calls);
        $this->assertSame(Config::apiBaseUrl() . '/auth/setup', $call['url']);
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

    public static function emailOverrideProvider(): array
    {
        // SetupRequest guarantees email_override is a valid email when present,
        // so the controller uses it verbatim; an absent override falls back to
        // the primary email.
        return [
            'override wins when present' => ['owner@example.com', 'owner@example.com'],
            'falls back to primary email when absent' => ['', 'admin@example.com'],
        ];
    }

    #[DataProvider('emailOverrideProvider')]
    public function test_handle_resolves_email_override(string $override, string $expectedEmail): void
    {
        WpHttpMock::queue(200, ['data' => ['status' => 'check_inbox']]);

        $this->handleSetup([...self::VALID_PARAMS, 'locale' => 'en_US', 'email_override' => $override]);

        $payload = $this->lastRequestJson();
        $this->assertSame($expectedEmail, $payload['email']);
        $this->assertSame('en_US', $payload['locale']);
    }

    // ---------------------------------------------------------------
    // status() — guard clauses (no backend call) — S1-S2
    // ---------------------------------------------------------------

    public function test_status_returns_401_when_not_logged_in(): void
    {
        \TestWpFunctions::setCurrentUser((object) ['ID' => 0]);

        $result = $this->pollStatus();

        $this->assertFailure($result, 401, ['next' => 'failed', 'code' => 'unauthenticated']);
        $this->assertSame([], WpHttpMock::$calls);
    }

    public function test_status_returns_410_when_no_pending_setup_token(): void
    {
        $result = $this->pollStatus();

        $this->assertFailure($result, 410, [
            'next' => 'failed',
            'code' => 'setup_expired',
            'message' => 'Your setup link has expired. Please start over.',
        ]);
        $this->assertSame([], WpHttpMock::$calls);
    }

    // ---------------------------------------------------------------
    // status() — finalize error mapping — S3-S7
    // ---------------------------------------------------------------

    public function test_status_returns_503_network_on_transport_error(): void
    {
        $this->stashPendingSetupToken();
        WpHttpMock::queueError('timeout');

        $result = $this->pollStatus();

        $this->assertFailure($result, 503, [
            'next' => 'failed',
            'code' => 'network',
            'message' => 'Could not reach paymentform.io.',
        ]);
    }

    public function test_status_maps_backend_410_to_setup_expired_and_drops_transient(): void
    {
        $this->stashPendingSetupToken();
        WpHttpMock::queue(410, ['error_code' => 'SETUP_EXPIRED']);

        $result = $this->pollStatus();

        $this->assertFailure($result, 410, [
            'next' => 'failed',
            'code' => 'setup_expired',
            'message' => 'Your setup link has expired. Please start over.',
        ]);
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

        $result = $this->pollStatus();

        $this->assertFailure($result, $statusCode, ['next' => 'failed', 'code' => 'finalize_failed']);
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

        $result = $this->pollStatus();

        $this->assertFailure($result, 502, ['next' => 'failed', 'code' => 'invalid_response']);
    }

    // ---------------------------------------------------------------
    // status() — finalize success paths — S8-S10
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

        $result = $this->pollStatus($tokenService);

        $this->assertFailure($result, 500, ['next' => 'failed', 'code' => 'storage_failed']);
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

        $result = $this->pollStatus();

        $this->assertSame(
            ['next' => 'connected', 'has_workspace' => true, 'email_verified' => true],
            $result
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

        $result = $this->pollStatus();

        $this->assertSame($expected, $result);
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
        $this->assertSame(Config::apiBaseUrl() . '/auth/setup/finalize', $call['url']);
        $this->assertSame('POST', $call['args']['method']);
        $this->assertSame(['setup_token' => 'v1.stashed-token'], $this->lastRequestJson());
    }

    // ---------------------------------------------------------------
    // failure() transport invariants (wp-kit Response singleton)
    // ---------------------------------------------------------------

    public function test_message_less_failure_clears_a_stale_singleton_message(): void
    {
        // Response is all-static: a previous response within the same PHP
        // request may have set a message. A message-less failure body must
        // clear it, not inherit it.
        Response::message('stale message from an earlier response');
        \TestWpFunctions::setCurrentUser((object) ['ID' => 0]);

        $result = $this->pollStatus(); // S1: unauthenticated, body has no message

        $this->assertFailure($result, 401, ['next' => 'failed', 'code' => 'unauthenticated']);
        $this->assertNull(Response::getMessage());
    }

    public function test_failure_throws_when_body_lacks_a_code(): void
    {
        $failure = new \ReflectionMethod(SetupController::class, 'failure');

        $this->expectException(\InvalidArgumentException::class);

        $failure->invoke($this->controller(), ['next' => 'failed'], 500);
    }
}
