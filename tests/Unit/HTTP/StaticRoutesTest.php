<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP;

use PaymentForm\HTTP\StaticRoutes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StaticRoutesTest extends TestCase
{
    /** Clears stored options between tests. */
    protected function setUp(): void
    {
        \TestWpFunctions::reset();
    }

    #[DataProvider('permalinkStructures')]
    public function test_path_matches_server_rewriting(string $structure, string $expected): void
    {
        update_option('permalink_structure', $structure);

        $this->assertSame($expected, StaticRoutes::path(StaticRoutes::SETUP_PROOF));
    }

    /** @return iterable<string, array{string, string}> */
    public static function permalinkStructures(): iterable
    {
        yield 'post name' => ['/%postname%/', '/paymentform/setup-proof'];
        yield 'numeric' => ['/archives/%post_id%/', '/paymentform/setup-proof'];
        yield 'plain' => ['', '/?paymentform_route=setup-proof'];
        yield 'PATHINFO' => ['/index.php/%postname%/', '/?paymentform_route=setup-proof'];
    }
}
