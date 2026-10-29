<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use PaymentForm\Deps\BitApps\WPKit\Http\Router\Route;
use PaymentForm\HTTP\StaticRouteDispatcher;
use PaymentForm\HTTP\StaticRoutes;

// Front-end route table for `/paymentform/*`, included by StaticRouteProvider
// via StaticRouter::loadRoutesFromFile(). These ride the page-rewrite engine
// rather than /wp-json, which some hosts and security plugins block.
//
// No nonce/cap middleware: callers are the OAuth redirect and the app
// backend, so each controller authenticates by state token or HMAC.
Route::get(StaticRoutes::CALLBACK, [StaticRouteDispatcher::class, 'callback']);
Route::post(StaticRoutes::WEBHOOK, [StaticRouteDispatcher::class, 'webhook']);
Route::get(StaticRoutes::SETUP_PROOF, [StaticRouteDispatcher::class, 'setupProof']);
