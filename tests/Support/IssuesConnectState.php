<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Support;

use PaymentForm\Services\ConnectService;

/**
 * Obtains a connect `state` through ConnectService's public API, the same way
 * the platform receives it (as a query param on the connect URL).
 */
trait IssuesConnectState
{
    /** Issue a state via createConnectUrl() and return it. */
    protected function issueConnectState(ConnectService $service): string
    {
        return $this->connectUrlParams($service->createConnectUrl())['state'];
    }

    /**
     * Parse the query parameters of a connect URL.
     *
     * @return array<string, string>
     */
    protected function connectUrlParams(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

        return $params;
    }
}
