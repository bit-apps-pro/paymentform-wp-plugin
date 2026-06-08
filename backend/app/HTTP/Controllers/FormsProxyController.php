<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

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
            $response = $this->apiClient->request('GET', '/api/forms');
            return new WP_REST_Response($response, 200);
        } catch (DisconnectedException) {
            return new WP_REST_Response([
                'status' => false,
                'code' => 'disconnected',
            ], 401);
        } catch (RuntimeException $e) {
            return new WP_REST_Response([
                'status' => false,
                'code' => 'api_error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($req): WP_REST_Response
    {
        $id = $req['id'];

        if (!is_numeric($id) || (int) $id <= 0) {
            return new WP_REST_Response([
                'status' => false,
                'code' => 'invalid_id',
            ], 400);
        }

        try {
            $response = $this->apiClient->request('GET', '/api/forms/' . (int) $id);
            return new WP_REST_Response($response, 200);
        } catch (DisconnectedException) {
            return new WP_REST_Response([
                'status' => false,
                'code' => 'disconnected',
            ], 401);
        } catch (RuntimeException $e) {
            return new WP_REST_Response([
                'status' => false,
                'code' => 'api_error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
