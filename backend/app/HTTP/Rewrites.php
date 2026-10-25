<?php

declare(strict_types=1);

namespace PaymentForm\HTTP;

use PaymentForm\Config;
use PaymentForm\Deps\BitApps\WPKit\Hooks\Hooks;
use PaymentForm\HTTP\Controllers\CallbackController;
use PaymentForm\HTTP\Controllers\SetupProofController;
use PaymentForm\HTTP\Controllers\WebhookController;
use PaymentForm\Providers\Container;
use WP;
use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Front-controller-style rewrite rules so the OAuth callback and incoming
 * webhook can be reached at `/paymentform/{callback,webhook}` instead of
 * `/wp-json/paymentform/v1/...`. Some managed-WP hosts and security plugins
 * block requests to `wp-json` / `wp-admin` / `wp-login` — friendly URLs ride
 * the normal page-rewrite engine so they survive those firewalls.
 */
final class Rewrites
{
    public const ROUTE_QUERY_VAR = 'paymentform_route';

    /**
     * Bump whenever rules change so `maybeFlush()` can auto-flush after an
     * upgrade without forcing the admin to manually visit Permalinks.
     */
    private const RULES_VERSION = '2';

    private const RULES_OPTION = 'rewrite_rules_version';

    public function __construct(private readonly Container $container) {}

    public function register(): void
    {
        Hooks::addAction('init', [$this, 'addRules']);
        Hooks::addAction('init', [$this, 'maybeFlush'], 11);
        Hooks::addFilter('query_vars', [$this, 'registerQueryVar']);
        Hooks::addAction('parse_request', [$this, 'dispatch']);
    }

    public function addRules(): void
    {
        add_rewrite_tag('%' . self::ROUTE_QUERY_VAR . '%', '([a-z_-]+)');
        add_rewrite_rule(
            '^paymentform/(callback|webhook|setup-proof)/?$',
            'index.php?' . self::ROUTE_QUERY_VAR . '=$matches[1]',
            'top'
        );
    }

    /**
     * @param  array<int, string>  $vars
     * @return array<int, string>
     */
    public function registerQueryVar(array $vars): array
    {
        $vars[] = self::ROUTE_QUERY_VAR;

        return $vars;
    }

    public function maybeFlush(): void
    {
        if (Config::getOption(self::RULES_OPTION) === self::RULES_VERSION) {
            return;
        }

        flush_rewrite_rules(false);
        Config::updateOption(self::RULES_OPTION, self::RULES_VERSION);
    }

    public function dispatch(WP $wp): void
    {
        $route = $wp->query_vars[self::ROUTE_QUERY_VAR] ?? null;

        if (! is_string($route) || $route === '') {
            return;
        }

        match ($route) {
            'callback' => $this->container[CallbackController::class]->handle($this->buildRequest($wp)),
            'webhook' => $this->respondAndExit(
                $this->container[WebhookController::class]->handle($this->buildRequest($wp))
            ),
            'setup-proof' => $this->container[SetupProofController::class]->handle($this->buildRequest($wp)),
            default => null,
        };
    }

    /**
     * The rewrite path never enters WP REST, so build a request object the
     * controllers can consume identically to the REST flow.
     */
    private function buildRequest(WP $wp): WP_REST_Request
    {
        $method = strtoupper(sanitize_key(wp_unslash((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))));
        $request = new WP_REST_Request($method, '/paymentform/' . $wp->query_vars[self::ROUTE_QUERY_VAR]);

        // phpcs:disable WordPress.Security.NonceVerification -- callback/webhook/setup-proof authenticate by state token or HMAC, not a WP nonce; controllers validate every param.
        foreach (wp_unslash($_GET) as $key => $value) {
            $request->set_param($key, $value);
        }
        foreach (wp_unslash($_POST) as $key => $value) {
            $request->set_param($key, $value);
        }
        // phpcs:enable WordPress.Security.NonceVerification

        $body = file_get_contents('php://input');
        if (is_string($body)) {
            $request->set_body($body);
        }

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_string($value)) {
                $header = str_replace('_', '-', substr($key, 5));
                $request->set_header($header, $value);
            }
        }

        return $request;
    }

    /**
     * `WebhookController` returns a `WP_REST_Response`; emit it and exit so
     * WP doesn't continue into the template layer.
     */
    private function respondAndExit(\WP_REST_Response $response): void
    {
        status_header($response->get_status());
        nocache_headers();
        header('Content-Type: application/json');
        echo wp_json_encode($response->get_data());
        exit;
    }
}
