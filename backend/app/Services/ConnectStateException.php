<?php

declare(strict_types=1);

namespace PaymentForm\Services;

use RuntimeException;

/** Thrown when a connect `state` row cannot be persisted, so no connect URL can be issued. */
final class ConnectStateException extends RuntimeException {}
