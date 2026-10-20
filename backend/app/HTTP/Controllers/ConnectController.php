<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use PaymentForm\Deps\BitApps\WPKit\Http\Request\Request;
use PaymentForm\Deps\BitApps\WPKit\Http\Response;
use PaymentForm\Enums\ErrorCode;
use PaymentForm\Providers\ServiceContainer;
use PaymentForm\Services\ConnectService;
use PaymentForm\Services\ConnectStateException;

final class ConnectController
{
    private readonly ConnectService $connectService;

    /** Resolve collaborators from the service container (wp-kit builds REST controllers with zero args). */
    public function __construct()
    {
        $this->connectService = ServiceContainer::get(ConnectService::class);
    }

    /**
     * Return a freshly issued platform connect URL for the admin to open.
     *
     * @return array{url: string}|Response
     */
    public function show(Request $req): array|Response
    {
        try {
            return ['url' => $this->connectService->createConnectUrl()];
        } catch (ConnectStateException) {
            return $this->errorResponse(ErrorCode::STORAGE_FAILED, 500);
        }
    }

    /**
     * Build the SPA error envelope (mirrors {@see LoginLinkController}); `message(null)` clears wp-kit's static Response.
     */
    private function errorResponse(ErrorCode $errorCode, int $status): Response
    {
        return Response::error(['status' => false, 'error_code' => $errorCode->value], $status)
            ->code($errorCode->value)
            ->message(null);
    }
}
