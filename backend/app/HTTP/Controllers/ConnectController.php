<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use PaymentForm\Config;
use PaymentForm\Services\ConnectService;
use WP_REST_Response;

final class ConnectController
{
    public function __construct(
        private readonly ConnectService $connectService,
    ) {}

    public function show($req): WP_REST_Response
    {
        $state = $this->connectService->generateState();
        $callbackUrl = home_url('/paymentform/callback');
        $connectUrl = $this->connectService->buildConnectUrl(
            Config::appBaseUrl(),
            $callbackUrl,
            $state
        );

        return new WP_REST_Response(['url' => $connectUrl], 200);
    }
}
