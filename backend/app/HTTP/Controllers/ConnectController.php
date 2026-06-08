<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use PaymentForm\Services\ConnectService;
use WP_REST_Request;
use WP_REST_Response;

final class ConnectController
{
    public function __construct(
        private readonly ConnectService $connectService,
    ) {}

    public function show($req): WP_REST_Response
    {
        $state = $this->connectService->generateState();
        $callbackUrl = rest_url('paymentform/v1/callback');
        $connectUrl = $this->connectService->buildConnectUrl(
            PAYMENTFORM_APP_BASE_URL,
            $callbackUrl,
            $state
        );

        return new WP_REST_Response(['url' => $connectUrl], 200);
    }
}
