<?php

declare(strict_types=1);

namespace PaymentForm\Providers;

use Pimple\Container;

final class ServiceContainer
{
    private static ?Container $instance = null;

    public static function instance(): Container
    {
        if (self::$instance === null) {
            self::$instance = new Container();
        }
        return self::$instance;
    }

    public static function get(string $id): mixed
    {
        return self::instance()->offsetGet($id);
    }
}
