<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Controllers;

use PaymentForm\Config;
use PaymentForm\Deps\BitApps\WPKit\Http\Response;
use PaymentForm\HTTP\Controllers\FormsProxyController;
use PaymentForm\HTTP\Requests\FormShowRequest;
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

class FormsProxyControllerTest extends TestCase
{
    use InteractsWithWpKit;
    use SeedsServiceContainer;

    private const FORM_ID = '11111111-1111-4111-8111-111111111111';

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

    private function connect(): void
    {
        $this->tokenService->store('test-token', (new \DateTime('+1 day'))->format(\DateTimeInterface::ATOM));
    }

    private function makeShowRequest(string $id): FormShowRequest
    {
        return $this->makeWpKitRequest(params: ['id' => $id], requestClass: FormShowRequest::class);
    }

    private function assertErrorResponse(mixed $response, int $httpStatus, string $errorCode): void
    {
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($httpStatus, Response::getHttpStatusCode());
        $this->assertSame($errorCode, Response::getCode());
        $this->assertSame(['status' => false, 'error_code' => $errorCode], Response::getData());
    }

    // Id-format validation moved to FormShowRequest (UUID), run by the wp-kit
    // router before the action; see FormShowRequestTest. These unit tests call
    // the action directly, so only a well-formed UUID reaches show().

    public function test_index_returns_bare_forms_array(): void
    {
        $this->connect();
        $forms = [['id' => 1, 'name' => 'Donation'], ['id' => 2, 'name' => 'Invoice']];
        WpHttpMock::queue(200, ['status' => true, 'data' => $forms]);

        $data = (new FormsProxyController())->index($this->makeWpKitRequest());

        $this->assertSame($forms, $data);
    }

    public function test_show_returns_bare_form_payload_and_proxies_uuid_path(): void
    {
        $this->connect();
        $form = ['id' => self::FORM_ID, 'name' => 'Donation'];
        WpHttpMock::queue(200, ['status' => true, 'data' => $form]);

        $data = (new FormsProxyController())->show($this->makeShowRequest(self::FORM_ID));

        $this->assertSame($form, $data);
        $call = end(WpHttpMock::$calls);
        $this->assertSame(Config::apiBaseUrl() . '/form/' . self::FORM_ID, $call['url']);
    }

    public function test_index_falls_back_to_full_response_when_data_is_not_an_array(): void
    {
        // A scalar `data` must not be returned bare (wp-kit success payloads
        // are arrays); the full upstream response is the safe fallback.
        $this->connect();
        $body = ['status' => true, 'data' => 'unexpected-scalar'];
        WpHttpMock::queue(200, $body);

        $data = (new FormsProxyController())->index($this->makeWpKitRequest());

        $this->assertSame($body, $data);
    }

    public function test_show_falls_back_to_full_response_when_data_is_missing(): void
    {
        $this->connect();
        $body = ['status' => true, 'message' => 'no data wrapper'];
        WpHttpMock::queue(200, $body);

        $data = (new FormsProxyController())->show($this->makeShowRequest(self::FORM_ID));

        $this->assertSame($body, $data);
    }

    public function test_index_returns_401_disconnected_when_token_revoked(): void
    {
        $this->connect();
        WpHttpMock::queue(401, 'Unauthorized'); // /form rejects the bearer
        WpHttpMock::queue(401, 'Unauthorized'); // /auth/refresh fails too

        $response = (new FormsProxyController())->index($this->makeWpKitRequest());

        $this->assertErrorResponse($response, 401, 'DISCONNECTED');
    }

    public function test_index_returns_500_api_error_on_upstream_failure(): void
    {
        $this->connect();
        WpHttpMock::queue(500, 'Internal Server Error');

        $response = (new FormsProxyController())->index($this->makeWpKitRequest());

        $this->assertErrorResponse($response, 500, 'API_ERROR');
    }
}
