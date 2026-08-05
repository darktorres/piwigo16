<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Piwigo\Admin\MenubarPageRenderer;

/**
 * MenubarPageRenderer::makeConsecutive()/absFnCmp() -- extracted from
 * include/admin/menubar.php's own top-level free functions during the P23
 * batch 6a port. Pure functions, no DB/globals needed.
 */
final class MenubarPageRendererMakeConsecutiveTest extends TestCase
{
    public function test_abs_fn_cmp_orders_by_magnitude(): void
    {
        self::assertSame(-1, MenubarPageRenderer::absFnCmp(1, 5) <=> 0);
        self::assertSame(1, MenubarPageRenderer::absFnCmp(5, 1) <=> 0);
        self::assertSame(0, MenubarPageRenderer::absFnCmp(3, -3));
    }

    public function test_make_consecutive_renumbers_to_consecutive_multiples_of_step(): void
    {
        $orders = [
            'a' => 150,
            'b' => 50,
            'c' => 100,
        ];

        MenubarPageRenderer::makeConsecutive($orders);

        self::assertSame(
            [
                'b' => 50,
                'c' => 100,
                'a' => 150,
            ],
            $orders
        );
    }

    public function test_make_consecutive_preserves_negative_sign_for_hidden_items(): void
    {
        // Sorted by magnitude first: 'hidden' (|-80|=80) before 'shown'
        // (|300|=300), so 'hidden' gets renumbered to the first consecutive
        // slot (-50) and 'shown' to the second (100), sign preserved.
        $orders = [
            'shown' => 300,
            'hidden' => -80,
        ];

        MenubarPageRenderer::makeConsecutive($orders);

        self::assertSame(-50, $orders['hidden']);
        self::assertSame(100, $orders['shown']);
    }

    public function test_make_consecutive_respects_a_custom_step(): void
    {
        $orders = [
            'a' => 2,
            'b' => 1,
        ];

        MenubarPageRenderer::makeConsecutive($orders, 10);

        self::assertSame(10, $orders['b']);
        self::assertSame(20, $orders['a']);
    }

    public function test_make_consecutive_handles_an_empty_array(): void
    {
        $orders = [];

        MenubarPageRenderer::makeConsecutive($orders);

        self::assertSame([], $orders);
    }

    public function test_make_consecutive_treats_a_zero_position_as_shown_not_hidden(): void
    {
        // The sign check is `$pos < 0` (strictly negative), so 0 itself
        // must land on the positive/shown side of the boundary.
        $orders = [
            'zero' => 0,
        ];

        MenubarPageRenderer::makeConsecutive($orders);

        self::assertSame(50, $orders['zero']);
    }

    public function test_make_consecutive_treats_negative_one_as_hidden(): void
    {
        // The other side of the same `$pos < 0` boundary.
        $orders = [
            'negative_one' => -1,
        ];

        MenubarPageRenderer::makeConsecutive($orders);

        self::assertSame(-50, $orders['negative_one']);
    }
}
