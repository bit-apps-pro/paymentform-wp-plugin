<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\Services;

use PaymentForm\Services\Http\HttpResponse;
use PaymentForm\Services\Http\HttpTransport;
use PaymentForm\Services\SetupApiClient;
use PHPUnit\Framework\TestCase;
use WpHttpMock;

/**
 * Pins the unauthenticated contract of SetupApiClient: it POSTs a JSON body to
 * the exact base-URL + path with the JSON `Accept` header and the caller's
 * timeout, decodes 2xx bodies to associative arrays, surfaces transport
 * failures via isError(), and NEVER sends an Authorization header (these
 * endpoints are pre-auth).
 */
class SetupApiClientTest extends TestCase
{
    private SetupApiClient $client;

    protected function setUp(): void
    {
        WpHttpMock::reset();
        $this->client = new SetupApiClient(new HttpTransport('https://api.test'));
    }

    /** The recorded args carry POST, the exact full URL, JSON body, Accept header and the passed timeout. */
    public function test_post_records_expected_request_args(): void
    {
        WpHttpMock::queue(200, ['ok' => true]);

        $this->client->post('/auth/setup', ['email' => 'admin@example.com'], 17);

        $this->assertCount(1, WpHttpMock::$calls);
        $call = WpHttpMock::$calls[0];

        // I4 exact-string match: base URL + path, byte-for-byte.
        $this->assertSame('https://api.test/auth/setup', $call['url']);
        $this->assertSame('POST', $call['args']['method']);
        $this->assertSame(17, $call['args']['timeout']);
        $this->assertSame('application/json', $call['args']['headers']['Accept']);
        // `json:` makes the adapter set Content-Type and encode the body.
        $this->assertSame('application/json', $call['args']['headers']['Content-Type']);
        $this->assertSame('{"email":"admin@example.com"}', $call['args']['body']);
    }

    /** The transport trims any trailing slash off the base URL before the path is appended. */
    public function test_post_trims_trailing_slash_from_base_url(): void
    {
        WpHttpMock::queue(200, ['ok' => true]);

        (new SetupApiClient(new HttpTransport('https://api.test/')))
            ->post('/auth/exchange', ['code' => 'abc']);

        $this->assertSame('https://api.test/auth/exchange', WpHttpMock::$calls[0]['url']);
    }

    /** The default timeout is 30 seconds when none is passed. */
    public function test_post_defaults_timeout_to_30_seconds(): void
    {
        WpHttpMock::queue(200, ['ok' => true]);

        $this->client->post('/auth/setup/diagnose', ['nonce' => 'n']);

        $this->assertSame(30, WpHttpMock::$calls[0]['args']['timeout']);
    }

    /** A 2xx JSON object decodes to an associative-array body. */
    public function test_post_decodes_2xx_body_to_assoc_array(): void
    {
        WpHttpMock::queue(200, ['data' => ['status' => 'connected'], 'flag' => true]);

        $res = $this->client->post('/auth/setup', ['email' => 'admin@example.com']);

        $this->assertInstanceOf(HttpResponse::class, $res);
        $this->assertFalse($res->isError());
        $this->assertSame(200, $res->status);
        $this->assertFalse($res->jsonError);
        $this->assertSame(['data' => ['status' => 'connected'], 'flag' => true], $res->body);
        $this->assertIsArray($res->body['data']);
    }

    /** A transport-layer WP_Error surfaces as isError() with status 0. */
    public function test_post_surfaces_transport_error(): void
    {
        WpHttpMock::queueError('cURL error 28: timeout');

        $res = $this->client->post('/auth/setup', ['email' => 'admin@example.com']);

        $this->assertTrue($res->isError());
        $this->assertSame(0, $res->status);
        $this->assertSame([], $res->body);
        $this->assertNotNull($res->error);
        $this->assertSame('cURL error 28: timeout', $res->error->get_error_message());
    }

    /** No Authorization header is ever sent — these endpoints are unauthenticated. */
    public function test_post_never_sends_authorization_header(): void
    {
        WpHttpMock::queue(200, ['ok' => true]);

        $this->client->post('/auth/exchange', ['code' => 'auth-code']);

        $this->assertArrayNotHasKey('Authorization', WpHttpMock::$calls[0]['args']['headers']);
    }
}
