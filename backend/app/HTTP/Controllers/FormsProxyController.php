<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use PaymentForm\Deps\BitApps\WPKit\Http\Request\Request;
use PaymentForm\Deps\BitApps\WPKit\Http\Response;
use PaymentForm\Enums\ErrorCode;
use PaymentForm\Providers\ServiceContainer;
use PaymentForm\Services\ApiClient;
use PaymentForm\Services\DisconnectedException;
use RuntimeException;

/**
 * Proxies the backend `/form` endpoints for the admin SPA + Gutenberg block.
 *
 * Success payloads are FLATTENED: the backend wraps its payload in
 * `{ data: ... }` and wp-kit adds its own `data` envelope, so we strip the
 * backend wrapper here and return the bare payload (migration-spec MAJOR-3).
 * Both `/forms` consumers unwrap exactly one envelope.
 */
final class FormsProxyController
{
    private readonly ApiClient $apiClient;

    public function __construct()
    {
        $this->apiClient = ServiceContainer::get(ApiClient::class);
    }

    /**
     * @return array<int|string, mixed>|Response
     */
    public function index(Request $req): array|Response
    {
        return $this->proxy('/form');
    }

    /**
     * @return array<int|string, mixed>|Response
     */
    public function show(Request $req): array|Response
    {
        $id = $req->get('id');

        // wp-kit's {id} route param matches [^/]+ (no inline regex
        // constraint), so the numeric guard lives here (migration-spec Risk #5).
        if (!is_numeric($id) || (int) $id <= 0) {
            return $this->errorResponse(ErrorCode::INVALID_FORM_ID, 400);
        }

        return $this->proxy('/form/' . (int) $id);
    }

    /**
     * Proxy a GET to the backend, flatten the success payload, and map
     * transport failures onto the SPA error envelope. Shared by index/show
     * so the catch ladder stays in one place.
     *
     * @return array<int|string, mixed>|Response
     */
    private function proxy(string $path): array|Response
    {
        try {
            return $this->flatten($this->apiClient->request('GET', $path));
        } catch (DisconnectedException) {
            return $this->errorResponse(ErrorCode::DISCONNECTED, 401);
        } catch (RuntimeException $e) {
            return $this->errorResponse(ErrorCode::API_ERROR, 500, $e->getMessage());
        }
    }

    /**
     * Strips the backend's `{ data: ... }` wrapper. Only an *array* payload
     * is unwrapped — a scalar/null `data` (or a response with no wrapper at
     * all) falls back to the full upstream response so a success path never
     * returns a non-array to wp-kit's envelope.
     *
     * @param array<int|string, mixed> $response
     *
     * @return array<int|string, mixed>
     */
    private function flatten(array $response): array
    {
        $data = $response['data'] ?? $response;

        return is_array($data) ? $data : $response;
    }

    /**
     * Build the error response for an upstream / validation failure. The
     * `error_code` field stays in the body for the SPA's error parser, and
     * the same value rides the wp-kit envelope `code`. Exception messages may
     * carry internal URLs or stack hints — only forward them when `WP_DEBUG`
     * is on so production sites don't leak detail to the authenticated admin.
     * `message(null)` clears any message a prior response left on wp-kit's
     * all-static Response singleton (same guard as SetupController::failure()).
     */
    private function errorResponse(ErrorCode $errorCode, int $status, ?string $message = null): Response
    {
        $body = ['status' => false, 'error_code' => $errorCode->value];

        if ($message !== null && defined('WP_DEBUG') && WP_DEBUG) {
            $body['message'] = $message;
        }

        return Response::error($body, $status)->code($errorCode->value)->message(null);
    }
}
