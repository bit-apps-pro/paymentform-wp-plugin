<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Support;

use PaymentForm\Providers\ServiceContainer;

/**
 * Seeds the static ServiceContainer with test doubles for the zero-arg REST
 * controllers that pull collaborators via ServiceContainer::get(...).
 *
 * Pair every seed with ServiceContainer::reset() in tearDown() so bindings
 * cannot leak between tests (the container is process-global static state).
 */
trait SeedsServiceContainer
{
    /**
     * @param array<class-string, object> $services binding id => ready-made instance
     */
    protected function seedContainer(array $services): void
    {
        $container = ServiceContainer::instance();

        foreach ($services as $id => $service) {
            $container[$id] = static fn (): object => $service;
        }
    }
}
