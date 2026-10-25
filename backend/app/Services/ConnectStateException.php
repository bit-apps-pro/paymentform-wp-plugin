<?php

declare(strict_types=1);

namespace PaymentForm\Services;

use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

/** Thrown when a connect `state` row cannot be persisted, so no connect URL can be issued. */
final class ConnectStateException extends RuntimeException {}
