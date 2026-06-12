<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\Services\Http;

use PaymentForm\Services\Http\HttpResponse;
use PaymentForm\Services\Http\HttpTransport;
use PHPUnit\Framework\TestCase;
use WpHttpMock;

/**
 * Pins the normalization contract of the wp-kit HttpClient adapter: the
 * recorded `wp_remote_request` `$args` shape (the legacy contract the
 * ApiClient/Setup tests assert), strict JSON decoding to an associative array,
 * transport-error handling, header lowercasing, and the exact-URL tripwire.
 *
 * The transport owns the base URL; callers pass a leading-slash relative path
 * and the recorded request URL is `base . $path`, byte-for-byte.
 */
class HttpTransportTest extends TestCase
{
    private const BASE_URL = 'https://api.test';

    private HttpTransport $transport;

    protected function setUp(): void
    {
        WpHttpMock::reset();
        $this->transport = new HttpTransport(self::BASE_URL);
    }

    /** The recorded args carry method/headers/body/timeout in the legacy shape. */
    public function test_records_legacy_request_args_shape(): void
    {
        WpHttpMock::queue(200, ['ok' => true]);

        $this->transport->send(
            'POST',
            '/things',
            ['Authorization' => 'Bearer abc', 'Accept' => 'application/json'],
            json: ['name' => 'widget'],
            timeoutSeconds: 17,
        );

        $args = WpHttpMock::$calls[0]['args'];

        $this->assertSame('POST', $args['method']);
        $this->assertSame(17, $args['timeout']);
        $this->assertSame('Bearer abc', $args['headers']['Authorization']);
        $this->assertSame('application/json', $args['headers']['Accept']);
        // `json:` makes the adapter set Content-Type and encode the body.
        $this->assertSame('application/json', $args['headers']['Content-Type']);
        $this->assertSame('{"name":"widget"}', $args['body']);
        // Header values are scalar strings, never `;`-joined arrays.
        foreach ($args['headers'] as $value) {
            $this->assertIsString($value);
        }
    }

    /** I4 tripwire: the recorded url is `base . $path`, byte-for-byte. */
    public function test_records_exact_full_url(): void
    {
        WpHttpMock::queue(200, ['ok' => true]);

        $path = '/ws-tenant/forms?include=fields';
        $this->transport->send('GET', $path);

        $this->assertSame(self::BASE_URL . $path, WpHttpMock::$calls[0]['url']);
    }

    /** A 2xx JSON object decodes to an associative array body. */
    public function test_2xx_returns_assoc_array_body(): void
    {
        WpHttpMock::queue(200, ['data' => ['nested' => ['a', 'b']], 'flag' => true]);

        $res = $this->transport->send('GET', '/x');

        $this->assertInstanceOf(HttpResponse::class, $res);
        $this->assertFalse($res->isError());
        $this->assertSame(200, $res->status);
        $this->assertFalse($res->jsonError);
        $this->assertSame(['data' => ['nested' => ['a', 'b']], 'flag' => true], $res->body);
        $this->assertIsArray($res->body['data']);
    }

    /** A transport-layer WP_Error surfaces as isError() with status 0. */
    public function test_transport_error_yields_error_and_zero_status(): void
    {
        WpHttpMock::queueError('cURL error 28: timeout');

        $res = $this->transport->send('GET', '/x');

        $this->assertTrue($res->isError());
        $this->assertSame(0, $res->status);
        $this->assertSame([], $res->body);
        $this->assertSame('', $res->rawBody);
        $this->assertNotNull($res->error);
        $this->assertSame('cURL error 28: timeout', $res->error->get_error_message());
    }

    /** Malformed JSON sets jsonError, body falls back to []; rawBody preserved. */
    public function test_malformed_json_sets_json_error(): void
    {
        WpHttpMock::queue(200, 'not valid json {');

        $res = $this->transport->send('GET', '/x');

        $this->assertFalse($res->isError());
        $this->assertSame(200, $res->status);
        $this->assertTrue($res->jsonError);
        $this->assertSame([], $res->body);
        $this->assertSame('not valid json {', $res->rawBody);
    }

    /** I5: a CANONICAL-cased response header is reachable lowercased. */
    public function test_canonical_cased_response_header_is_lowercased(): void
    {
        WpHttpMock::queue(429, ['error' => 'rate_limited'], ['Retry-After' => '45']);

        $res = $this->transport->send('POST', '/x');

        $this->assertSame(429, $res->status);
        $this->assertArrayHasKey('retry-after', $res->headers);
        $this->assertSame('45', $res->headers['retry-after']);
    }

    /** Array-valued headers collapse to their first element. */
    public function test_array_valued_header_collapses_to_first_element(): void
    {
        WpHttpMock::queue(200, ['ok' => true], ['X-Multi' => ['first', 'second']]);

        $res = $this->transport->send('GET', '/x');

        $this->assertSame('first', $res->headers['x-multi']);
    }

    /** A body decoding to a non-array scalar yields body []. */
    public function test_scalar_body_yields_empty_array(): void
    {
        // A bare JSON number is valid JSON but not an object/array.
        WpHttpMock::queue(200, '12345');

        $res = $this->transport->send('GET', '/x');

        $this->assertFalse($res->jsonError);
        $this->assertSame([], $res->body);
        $this->assertSame('12345', $res->rawBody);
    }

    /** An empty body yields body [] and empty rawBody. */
    public function test_empty_body_yields_empty_array(): void
    {
        WpHttpMock::queue(204, '');

        $res = $this->transport->send('GET', '/x');

        $this->assertSame(204, $res->status);
        $this->assertFalse($res->jsonError);
        $this->assertSame([], $res->body);
        $this->assertSame('', $res->rawBody);
    }

    /** A falsy-but-present body ([] / {} / 0 / false) still yields body []. */
    public function test_falsy_json_bodies_yield_empty_array(): void
    {
        foreach (['[]', '{}', '0', 'false'] as $rawBody) {
            WpHttpMock::reset();
            WpHttpMock::queue(200, $rawBody);

            $res = $this->transport->send('GET', '/x');

            $this->assertFalse($res->jsonError, "jsonError for body {$rawBody}");
            $this->assertSame([], $res->body, "body for {$rawBody}");
        }
    }

    /** Status is read from the response code independent of the body content. */
    public function test_status_read_independent_of_body(): void
    {
        WpHttpMock::queue(503, '');

        $res = $this->transport->send('GET', '/x');

        $this->assertSame(503, $res->status);
        $this->assertSame([], $res->body);
    }
}
