<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Support;

use PaymentForm\Deps\BitApps\WPKit\Http\Request\Request;
use PaymentForm\Deps\BitApps\WPKit\Http\Response;
use ReflectionClass;

/**
 * Helpers for tests touching the wp-kit HTTP layer.
 */
trait InteractsWithWpKit
{
    /**
     * Builds a wp-kit Request wrapping a MockWpRestRequest, mirroring how
     * RouteRegister hydrates the request before middleware run
     * (Request::setApiRequest()).
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>  $params  exposed as query params
     */
    protected function makeWpKitRequest(
        array $headers = [],
        array $params = [],
        string $method = 'GET',
        string $path = '/paymentform/v1/test',
    ): Request {
        $request = new Request();
        $request->setApiRequest(new MockWpRestRequest($method, $path, $params, '', $headers));

        return $request;
    }

    /**
     * wp-kit's Response is an all-static singleton with no reset API; null
     * its state via reflection so error payloads cannot bleed across tests
     * (migration-spec Risk #6).
     */
    protected function resetWpKitResponse(): void
    {
        $defaults = [
            '_instance'   => null,
            '_message'    => null,
            '_status'     => null,
            '_code'       => null,
            '_data'       => null,
            '_httpStatus' => null,
            '_headers'    => [],
        ];

        $reflection = new ReflectionClass(Response::class);
        foreach ($defaults as $property => $default) {
            $reflection->getProperty($property)->setValue(null, $default);
        }
    }
}
