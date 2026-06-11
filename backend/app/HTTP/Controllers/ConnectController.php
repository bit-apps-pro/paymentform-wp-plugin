<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use PaymentForm\Config;
use PaymentForm\Deps\BitApps\WPKit\Http\Request\Request;
use PaymentForm\Providers\ServiceContainer;
use PaymentForm\Services\ConnectService;

final class ConnectController
{
    private readonly ConnectService $connectService;

    public function __construct()
    {
        $this->connectService = ServiceContainer::get(ConnectService::class);
    }

    /**
     * @return array{url: string}
     */
    public function show(Request $req): array
    {
        $state = $this->connectService->generateState();
        $callbackUrl = home_url('/paymentform/callback');
        $connectUrl = $this->connectService->buildConnectUrl(
            Config::appBaseUrl(),
            $callbackUrl,
            $state
        );

        return ['url' => $connectUrl];
    }
}
