<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Support;

use PaymentForm\Deps\BitApps\WPKit\Http\Request\Request;
use PaymentForm\Deps\BitApps\WPKit\Http\Response;

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
     * Pass $requestClass to build a typed Request subclass (e.g. SetupRequest)
     * for controller actions that type-hint one — the router would instantiate
     * that subclass; these unit tests call the action directly, so they must
     * supply the matching type. Format validation in rules() is NOT exercised
     * here (the router runs it, not the direct call), so it is covered by the
     * Request's own test instead.
     *
     * @template T of Request
     *
     * @param array<string, string>  $headers
     * @param array<string, mixed>   $params       exposed as query params
     * @param class-string<T>         $requestClass
     *
     * @return T
     */
    protected function makeWpKitRequest(
        array $headers = [],
        array $params = [],
        string $method = 'GET',
        string $path = '/paymentform/v1/test',
        string $requestClass = Request::class,
    ): Request {
        $request = new $requestClass();
        $request->setApiRequest(new MockWpRestRequest($method, $path, $params, '', $headers));

        return $request;
    }

    /**
     * Starts each test on a fresh wp-kit Response so error payloads cannot bleed across tests.
     */
    protected function resetWpKitResponse(): void
    {
        Response::reset();
    }
}
