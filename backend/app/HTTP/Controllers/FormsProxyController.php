<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use PaymentForm\Enums\ErrorCode;
use PaymentForm\Services\ApiClient;
use PaymentForm\Services\DisconnectedException;
use RuntimeException;
use WP_REST_Response;

final class FormsProxyController
{
    public function __construct(
        private readonly ApiClient $apiClient,
    ) {}

    public function index($req): WP_REST_Response
    {
        try {
            $response = $this->apiClient->request('GET', '/form');
            return new WP_REST_Response($response, 200);
        } catch (DisconnectedException) {
            return $this->errorResponse(ErrorCode::DISCONNECTED, 401);
        } catch (RuntimeException $e) {
            return $this->errorResponse(ErrorCode::API_ERROR, 500, $e->getMessage());
        }
    }

    public function show($req): WP_REST_Response
    {
        $id = $req['id'];

        if (!is_numeric($id) || (int) $id <= 0) {
            return $this->errorResponse(ErrorCode::INVALID_FORM_ID, 400);
        }

        try {
            $response = $this->apiClient->request('GET', '/form/' . (int) $id);
            return new WP_REST_Response($response, 200);
        } catch (DisconnectedException) {
            return $this->errorResponse(ErrorCode::DISCONNECTED, 401);
        } catch (RuntimeException $e) {
            return $this->errorResponse(ErrorCode::API_ERROR, 500, $e->getMessage());
        }
    }

    /**
     * Build the JSON body for an upstream / validation failure. Exception
     * messages may carry internal URLs or stack hints — only forward them
     * when `WP_DEBUG` is on so production sites don't leak detail to the
     * authenticated admin.
     */
    private function errorResponse(ErrorCode $errorCode, int $status, ?string $message = null): WP_REST_Response
    {
        $body = ['status' => false, 'error_code' => $errorCode->value];

        if ($message !== null && defined('WP_DEBUG') && WP_DEBUG) {
            $body['message'] = $message;
        }

        return new WP_REST_Response($body, $status);
    }
}
