<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit;

use PaymentForm\Services\ApiClient;
use PaymentForm\Services\DisconnectedException;
use PaymentForm\Services\EncryptionService;
use PaymentForm\Services\Http\HttpTransport;
use PaymentForm\Services\TokenRefreshLock;
use PaymentForm\Services\TokenService;
use PHPUnit\Framework\TestCase;
use WpHttpMock;

class ApiClientTest extends TestCase
{
    private TokenService $tokenService;
    private TokenRefreshLock $refreshLock;
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        WpHttpMock::reset();
        $this->encryption = new EncryptionService();
        $this->tokenService = new TokenService($this->encryption);
        $this->refreshLock = new TokenRefreshLock();

        $this->tokenService->store('test-token', (new \DateTime('+1 day'))->format(\DateTimeInterface::ATOM));
    }

    private function client(): ApiClient
    {
        return new ApiClient($this->tokenService, $this->refreshLock, new HttpTransport('https://api.test'));
    }

    public function test_successful_request(): void
    {
        WpHttpMock::queue(200, ['data' => 'test']);

        $this->assertSame(['data' => 'test'], $this->client()->request('GET', '/test'));
    }

    public function test_sets_bearer_token_header(): void
    {
        WpHttpMock::queue(200, ['ok' => true]);

        $this->client()->request('GET', '/status');

        $this->assertSame('Bearer test-token', WpHttpMock::$calls[0]['args']['headers']['Authorization']);
    }

    public function test_401_triggers_refresh_and_retries(): void
    {
        WpHttpMock::queue(401, 'Unauthorized');
        WpHttpMock::queue(200, ['data' => ['token' => 'new-token', 'expires_at' => '2026-06-09T12:00:00Z']]);
        WpHttpMock::queue(200, ['success' => true]);

        $result = $this->client()->request('GET', '/data');

        $this->assertSame(['success' => true], $result);
        $this->assertSame('new-token', $this->tokenService->get());
    }

    public function test_second_401_clears_token_and_throws(): void
    {
        WpHttpMock::queue(401, 'Unauthorized');
        WpHttpMock::queue(200, ['data' => ['token' => 'new-token', 'expires_at' => '2026-06-09T12:00:00Z']]);
        WpHttpMock::queue(401, 'Unauthorized');

        $this->expectException(DisconnectedException::class);
        $this->expectExceptionMessage('TOKEN_REVOKED');

        $this->client()->request('GET', '/data');
    }

    public function test_5xx_error_throws_api_exception(): void
    {
        WpHttpMock::queue(500, 'Internal Server Error');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/API_ERROR_500/');

        $this->client()->request('GET', '/data');
    }

    public function test_long_multibyte_error_body_is_truncated_without_losing_the_message(): void
    {
        WpHttpMock::queue(502, str_repeat('é', 600));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API_ERROR_502: ' . str_repeat('é', 500) . '…');

        $this->client()->request('GET', '/data');
    }

    public function test_no_token_throws(): void
    {
        \TestWpFunctions::reset();
        $tokenService = new TokenService($this->encryption);

        $apiClient = new ApiClient($tokenService, $this->refreshLock, new HttpTransport('https://api.test'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('NO_TOKEN');

        $apiClient->request('GET', '/data');
    }

    public function test_authorization_header_survives_user_headers(): void
    {
        WpHttpMock::queue(200, ['ok' => true]);

        $this->client()->request('POST', '/test', ['headers' => ['X-Custom' => 'v']]);

        $headers = WpHttpMock::$calls[0]['args']['headers'];
        $this->assertSame('Bearer test-token', $headers['Authorization']);
        $this->assertSame('v', $headers['X-Custom']);
    }

    public function test_refresh_lock_acquired_and_released_on_401(): void
    {
        WpHttpMock::queue(401, 'Unauthorized');
        WpHttpMock::queue(200, ['data' => ['token' => 'new-token', 'expires_at' => '2026-06-09T12:00:00Z']]);
        WpHttpMock::queue(200, ['ok' => true]);

        $refreshLock = new TokenRefreshLock();
        $result = (new ApiClient($this->tokenService, $refreshLock, new HttpTransport('https://api.test')))
            ->request('GET', '/data');

        $this->assertSame(['ok' => true], $result);
        $this->assertTrue($refreshLock->acquire());
    }

    public function test_invalid_json_response_throws_runtime_exception(): void
    {
        WpHttpMock::queue(200, 'not valid json {');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('INVALID_JSON_RESPONSE');

        $this->client()->request('GET', '/data');
    }

    public function test_refresh_lock_released_before_retry_request(): void
    {
        WpHttpMock::queue(401, 'Unauthorized');
        WpHttpMock::queue(200, ['data' => ['token' => 'new-token', 'expires_at' => '2026-06-09T12:00:00Z']]);
        WpHttpMock::queue(200, ['ok' => true]);

        $refreshLock = new TokenRefreshLock();
        (new ApiClient($this->tokenService, $refreshLock, new HttpTransport('https://api.test')))->request('GET', '/data');

        $this->assertTrue($refreshLock->acquire());
    }

    public function test_request_prepends_tenant_prefix_for_non_auth_paths(): void
    {
        WpHttpMock::queue(200, ['data' => 'test']);

        $this->tokenService->store('test-token', (new \DateTime('+1 day'))->format(\DateTimeInterface::ATOM), 'ws-test');

        $this->client()->request('GET', '/me');

        $this->assertStringContainsString('/ws-test/me', WpHttpMock::$calls[0]['url']);
    }

    public function test_refresh_path_not_prefixed_with_tenant(): void
    {
        WpHttpMock::queue(200, ['data' => ['token' => 'new-token', 'expires_at' => '2026-06-09T12:00:00Z']]);

        $this->tokenService->store('test-token', (new \DateTime('+1 day'))->format(\DateTimeInterface::ATOM), 'ws-test');

        $this->client()->refresh();

        $url = WpHttpMock::$calls[0]['url'];
        $this->assertStringContainsString('/auth/refresh', $url);
        $this->assertStringNotContainsString('/ws-test/auth', $url);
    }
}
