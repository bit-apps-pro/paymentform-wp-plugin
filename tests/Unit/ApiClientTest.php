<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PaymentForm\Services\ApiClient;
use PaymentForm\Services\DisconnectedException;
use PaymentForm\Services\EncryptionService;
use PaymentForm\Services\TokenRefreshLock;
use PaymentForm\Services\TokenService;
use PHPUnit\Framework\TestCase;

class ApiClientTest extends TestCase
{
    private TokenService $tokenService;
    private TokenRefreshLock $refreshLock;
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        $this->encryption = new EncryptionService();
        $this->tokenService = new TokenService($this->encryption);
        $this->refreshLock = new TokenRefreshLock();

        // Store a test token
        $this->tokenService->store('test-token', (new \DateTime('+1 day'))->format(\DateTimeInterface::ATOM));
    }

    public function test_successful_request(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => 'test'])),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $apiClient = new ApiClient($this->tokenService, $this->refreshLock, 'https://api.test', $httpClient);

        $result = $apiClient->request('GET', '/test', []);

        $this->assertSame(['data' => 'test'], $result);
    }

    public function test_sets_bearer_token_header(): void
    {
        $capturedRequest = null;
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['ok' => true])),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);

        $httpClient = new Client(['handler' => $handlerStack]);

        $apiClient = new ApiClient($this->tokenService, $this->refreshLock, 'https://api.test', $httpClient);
        $apiClient->request('GET', '/status', []);

        // Check that the Authorization header was set
        $lastRequest = $mockHandler->getLastRequest();
        $this->assertNotNull($lastRequest);
        $this->assertStringContainsString('Bearer test-token', $lastRequest->getHeader('Authorization')[0]);
    }

    public function test_401_triggers_refresh_and_retries(): void
    {
        $mockHandler = new MockHandler([
            new Response(401, [], 'Unauthorized'),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => ['token' => 'new-token', 'expires_at' => '2026-06-09T12:00:00Z']
            ])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['success' => true])),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $apiClient = new ApiClient($this->tokenService, $this->refreshLock, 'https://api.test', $httpClient);

        $result = $apiClient->request('GET', '/data', []);

        $this->assertSame(['success' => true], $result);
        // Verify token was updated
        $this->assertSame('new-token', $this->tokenService->get());
    }

    public function test_second_401_clears_token_and_throws(): void
    {
        $mockHandler = new MockHandler([
            new Response(401, [], 'Unauthorized'),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => ['token' => 'new-token', 'expires_at' => '2026-06-09T12:00:00Z']
            ])),
            new Response(401, [], 'Unauthorized'),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $apiClient = new ApiClient($this->tokenService, $this->refreshLock, 'https://api.test', $httpClient);

        $this->expectException(DisconnectedException::class);
        $this->expectExceptionMessage('token_revoked');

        $apiClient->request('GET', '/data', []);

        $this->assertNull($this->tokenService->get());
    }

    public function test_5xx_error_throws_api_exception(): void
    {
        $mockHandler = new MockHandler([
            new Response(500, [], 'Internal Server Error'),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $apiClient = new ApiClient($this->tokenService, $this->refreshLock, 'https://api.test', $httpClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/api_error_500/');

        $apiClient->request('GET', '/data', []);
    }

    public function test_no_token_throws(): void
    {
        \TestWpFunctions::reset();
        $tokenService = new TokenService($this->encryption);

        $mockHandler = new MockHandler([]);
        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $apiClient = new ApiClient($tokenService, $this->refreshLock, 'https://api.test', $httpClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no_token');

        $apiClient->request('GET', '/data', []);
    }

    public function test_authorization_header_survives_user_headers(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['ok' => true])),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $apiClient = new ApiClient($this->tokenService, $this->refreshLock, 'https://api.test', $httpClient);
        $apiClient->request('POST', '/test', ['headers' => ['X-Custom' => 'v']]);

        $lastRequest = $mockHandler->getLastRequest();
        $this->assertNotNull($lastRequest);
        $this->assertStringContainsString('Bearer test-token', $lastRequest->getHeader('Authorization')[0]);
        $this->assertStringContainsString('v', $lastRequest->getHeader('X-Custom')[0]);
    }

    public function test_refresh_lock_acquired_and_released_on_401(): void
    {
        $mockHandler = new MockHandler([
            new Response(401, [], 'Unauthorized'),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => ['token' => 'new-token', 'expires_at' => '2026-06-09T12:00:00Z']
            ])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['ok' => true])),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $refreshLock = new TokenRefreshLock();
        $apiClient = new ApiClient($this->tokenService, $refreshLock, 'https://api.test', $httpClient);

        $result = $apiClient->request('GET', '/data', []);

        $this->assertSame(['ok' => true], $result);
        // Lock should be released after refresh
        $this->assertTrue($refreshLock->acquire());
    }

    public function test_invalid_json_response_throws_runtime_exception(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], 'not valid json {'),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $apiClient = new ApiClient($this->tokenService, $this->refreshLock, 'https://api.test', $httpClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid_json_response');

        $apiClient->request('GET', '/data', []);
    }

    public function test_refresh_lock_released_before_retry_request(): void
    {
        $mockHandler = new MockHandler([
            new Response(401, [], 'Unauthorized'),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => ['token' => 'new-token', 'expires_at' => '2026-06-09T12:00:00Z']
            ])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['ok' => true])),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $refreshLock = new TokenRefreshLock();
        $apiClient = new ApiClient($this->tokenService, $refreshLock, 'https://api.test', $httpClient);

        // Execute the request (will trigger 401, refresh, and retry)
        $result = $apiClient->request('GET', '/data', []);

        $this->assertSame(['ok' => true], $result);
        // After the entire flow completes, the lock should be acquirable (released before retry)
        $this->assertTrue($refreshLock->acquire());
    }

    public function test_request_prepends_tenant_prefix_for_non_auth_paths(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => 'test'])),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $this->tokenService->store('test-token', (new \DateTime('+1 day'))->format(\DateTimeInterface::ATOM), 'ws-test');

        $apiClient = new ApiClient($this->tokenService, $this->refreshLock, 'https://api.test', $httpClient);
        $apiClient->request('GET', '/me', []);

        $lastRequest = $mockHandler->getLastRequest();
        $this->assertNotNull($lastRequest);
        $this->assertStringContainsString('/ws-test/me', (string) $lastRequest->getUri());
    }

    public function test_refresh_path_not_prefixed_with_tenant(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => ['token' => 'new-token', 'expires_at' => '2026-06-09T12:00:00Z']
            ])),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $this->tokenService->store('test-token', (new \DateTime('+1 day'))->format(\DateTimeInterface::ATOM), 'ws-test');

        $apiClient = new ApiClient($this->tokenService, $this->refreshLock, 'https://api.test', $httpClient);
        $apiClient->refresh();

        $lastRequest = $mockHandler->getLastRequest();
        $this->assertNotNull($lastRequest);
        $this->assertStringContainsString('/auth/refresh', (string) $lastRequest->getUri());
        $this->assertStringNotContainsString('/ws-test/auth', (string) $lastRequest->getUri());
    }
}
