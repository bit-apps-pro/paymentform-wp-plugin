<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Controllers;

use PaymentForm\Config;
use PaymentForm\Deps\BitApps\WPKit\Http\Response;
use PaymentForm\HTTP\Controllers\LoginLinkController;
use PaymentForm\HTTP\Requests\LoginLinkRequest;
use PaymentForm\Providers\ServiceContainer;
use PaymentForm\Services\ApiClient;
use PaymentForm\Services\EncryptionService;
use PaymentForm\Services\Http\HttpTransport;
use PaymentForm\Services\TokenRefreshLock;
use PaymentForm\Services\TokenService;
use PaymentForm\Tests\Support\InteractsWithWpKit;
use PaymentForm\Tests\Support\SeedsServiceContainer;
use PHPUnit\Framework\TestCase;
use WpHttpMock;

class LoginLinkControllerTest extends TestCase
{
    use InteractsWithWpKit;
    use SeedsServiceContainer;

    private const SSO_PATH = '/auth/sso?ticket=abc123';

    private TokenService $tokenService;

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        WpHttpMock::reset();
        $this->resetWpKitResponse();
        $this->tokenService = new TokenService(new EncryptionService());
        $this->seedContainer([
            ApiClient::class => new ApiClient(
                $this->tokenService,
                new TokenRefreshLock(),
                new HttpTransport(Config::apiBaseUrl())
            ),
        ]);
    }

    protected function tearDown(): void
    {
        ServiceContainer::reset();
        $this->resetWpKitResponse();
    }

    /** A minted URL on the configured dashboard origin. */
    private function mintedUrl(): string
    {
        return Config::appBaseUrl() . self::SSO_PATH;
    }

    private function connect(): void
    {
        $this->tokenService->store('test-token', (new \DateTime('+1 day'))->format(\DateTimeInterface::ATOM));
    }

    private function makeRequest(array $params = []): LoginLinkRequest
    {
        return $this->makeWpKitRequest(params: $params, method: 'POST', requestClass: LoginLinkRequest::class);
    }

    private function lastForwardedRedirect(): mixed
    {
        $call = end(WpHttpMock::$calls);
        $body = json_decode((string) ($call['args']['body'] ?? ''), true);

        return $body['redirect'] ?? null;
    }

    private function assertErrorResponse(mixed $response, int $httpStatus, string $errorCode): void
    {
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($httpStatus, Response::getHttpStatusCode());
        $this->assertSame($errorCode, Response::getCode());
        $this->assertSame(['status' => false, 'error_code' => $errorCode], Response::getData());
    }

    public function test_returns_minted_url_and_hits_non_tenant_prefixed_path(): void
    {
        $this->connect();
        WpHttpMock::queue(200, ['status' => true, 'data' => ['url' => $this->mintedUrl(), 'expires_in' => 60]]);

        $data = (new LoginLinkController())->create($this->makeRequest(['redirect' => '/build/abc']));

        $this->assertSame(['url' => $this->mintedUrl()], $data);
        $call = end(WpHttpMock::$calls);
        // /auth/* paths are NOT tenant-prefixed even with a tenant stored.
        $this->assertSame(Config::apiBaseUrl() . '/auth/login-link', $call['url']);
    }

    public function test_forwards_supplied_redirect_to_the_backend(): void
    {
        $this->connect();
        WpHttpMock::queue(200, ['status' => true, 'data' => ['url' => $this->mintedUrl()]]);

        (new LoginLinkController())->create($this->makeRequest(['redirect' => '/build/abc']));

        $this->assertSame('/build/abc', $this->lastForwardedRedirect());
    }

    public function test_defaults_redirect_to_myforms_when_absent(): void
    {
        $this->connect();
        WpHttpMock::queue(200, ['status' => true, 'data' => ['url' => $this->mintedUrl()]]);

        (new LoginLinkController())->create($this->makeRequest());

        $this->assertSame('/myforms', $this->lastForwardedRedirect());
    }

    public function test_rejects_open_redirect_and_falls_back_to_default(): void
    {
        $this->connect();
        WpHttpMock::queue(200, ['status' => true, 'data' => ['url' => $this->mintedUrl()]]);

        // A protocol-relative redirect must never reach the backend verbatim;
        // the controller swaps it for the safe default (defense-in-depth — the
        // router rules() do not run on a direct action call).
        (new LoginLinkController())->create($this->makeRequest(['redirect' => '//evil.example']));

        $this->assertSame('/myforms', $this->lastForwardedRedirect());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function foreignUrls(): array
    {
        return [
            'other origin' => ['https://evil.example/auth/sso?ticket=x'],
            'javascript scheme' => ['javascript:alert(document.domain)'],
            'relative path' => ['/auth/sso?ticket=x'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignUrls')]
    public function test_rejects_minted_url_outside_the_app_origin(string $url): void
    {
        $this->connect();
        WpHttpMock::queue(200, ['status' => true, 'data' => ['url' => $url]]);

        $response = (new LoginLinkController())->create($this->makeRequest());

        $this->assertErrorResponse($response, 502, 'INVALID_RESPONSE');
    }

    public function test_returns_invalid_response_when_url_missing(): void
    {
        $this->connect();
        WpHttpMock::queue(200, ['status' => true, 'data' => ['expires_in' => 60]]);

        $response = (new LoginLinkController())->create($this->makeRequest());

        $this->assertErrorResponse($response, 502, 'INVALID_RESPONSE');
    }

    public function test_returns_disconnected_when_token_revoked(): void
    {
        $this->connect();
        WpHttpMock::queue(401, 'Unauthorized'); // mint rejects the bearer
        WpHttpMock::queue(401, 'Unauthorized'); // /auth/refresh fails too

        $response = (new LoginLinkController())->create($this->makeRequest());

        $this->assertErrorResponse($response, 401, 'DISCONNECTED');
    }

    public function test_returns_api_error_on_upstream_failure(): void
    {
        $this->connect();
        WpHttpMock::queue(500, 'Internal Server Error');

        $response = (new LoginLinkController())->create($this->makeRequest());

        $this->assertErrorResponse($response, 502, 'API_ERROR');
    }
}
