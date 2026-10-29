<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use PaymentForm\Deps\BitApps\WPKit\Http\Router\Route;
use PaymentForm\HTTP\Controllers\ConnectController;
use PaymentForm\HTTP\Controllers\DisconnectController;
use PaymentForm\HTTP\Controllers\FormsProxyController;
use PaymentForm\HTTP\Controllers\LoginLinkController;
use PaymentForm\HTTP\Controllers\RefreshController;
use PaymentForm\HTTP\Controllers\ResendOtpController;
use PaymentForm\HTTP\Controllers\SetupController;
use PaymentForm\HTTP\Controllers\SetupDiagnoseController;
use PaymentForm\HTTP\Controllers\StatusController;
use PaymentForm\HTTP\Controllers\VerifyOtpController;

// Declarative REST route table for `paymentform/v1`, included by
// HookProvider::loadApiRoutes() via Router::registerFile().
//
// /callback, /webhook and /setup-proof are static routes
// (see hooks/static.php) and are intentionally NOT part of this table.
//
// SECURITY: every route MUST carry BOTH `nonce` and `cap:paymentform_manage`.
// wp-kit registers each route with `permission_callback => '__return_true'`,
// so these two middleware are the only authentication/authorization layer.
//
// Middleware form — a SINGLE variadic ->middleware('nonce', 'cap:…') call on
// the group, nonce first. Group middleware does propagate to every route in
// the closure: each RouteRegister keeps a reference to the group RouteBase
// and RouteRegister::getMiddleware() merges the group's list in ahead of any
// per-route middleware. Do NOT split this into two chained ->middleware()
// calls: RouteBase::middleware() accumulates with array union (`+`), so a
// second chained call collides on key 0 and is SILENTLY DROPPED (verified
// against vendor/bitapps/wp-kit/src/Http/Router/RouteBase.php; locked by
// ApiRouteRegistrationTest).
Route::group(static function (): void {
    Route::get('connect-url', [ConnectController::class, 'show']);
    Route::get('status', [StatusController::class, 'show']);
    Route::post('refresh', [RefreshController::class, 'refresh']);
    Route::post('disconnect', [DisconnectController::class, 'disconnect']);
    Route::post('login-link', [LoginLinkController::class, 'create']);
    Route::get('forms', [FormsProxyController::class, 'index']);
    // wp-kit's {id} matches [^/]+ (no inline regex constraint); FormShowRequest
    // validates that the id is a UUID and answers 400 on a malformed id.
    Route::get('forms/{id}', [FormsProxyController::class, 'show']);
    Route::post('setup', [SetupController::class, 'handle']);
    Route::post('setup/diagnose', [SetupDiagnoseController::class, 'handle']);
    Route::post('setup/verify-otp', [VerifyOtpController::class, 'handle']);
    Route::post('setup/resend-otp', [ResendOtpController::class, 'handle']);
    Route::get('setup/status', [SetupController::class, 'status']);
})->middleware('nonce', 'cap:paymentform_manage');
