<?php

declare(strict_types=1);

use Piwigo\Bootstrap\UserBootstrap;

/**
 * UserBootstrap::shouldUseUserCache() -- the $user_use_cache decision
 * extracted from include/user.inc.php during its P23 batch 5 port. Pure
 * function, no DB/globals needed.
 */
final class UserBootstrapShouldUseUserCacheTest extends \PHPUnit\Framework\TestCase
{
    public function test_true_by_default(): void
    {
        self::assertTrue(UserBootstrap::shouldUseUserCache(false, false, null));
    }

    public function test_false_when_in_admin(): void
    {
        self::assertFalse(UserBootstrap::shouldUseUserCache(true, false, null));
    }

    public function test_false_when_a_ws_method_is_requested_from_an_admin_referer(): void
    {
        self::assertFalse(UserBootstrap::shouldUseUserCache(
            false,
            true,
            'https://example.test/admin.php?page=photo-1'
        ));
    }

    public function test_true_when_a_ws_method_is_requested_from_a_non_admin_referer(): void
    {
        self::assertTrue(UserBootstrap::shouldUseUserCache(
            false,
            true,
            'https://example.test/index.php'
        ));
    }

    public function test_true_when_the_referer_is_missing_even_if_a_method_is_requested(): void
    {
        self::assertTrue(UserBootstrap::shouldUseUserCache(false, true, null));
    }

    public function test_in_admin_wins_even_with_an_admin_referer(): void
    {
        self::assertFalse(UserBootstrap::shouldUseUserCache(
            true,
            true,
            'https://example.test/admin.php?page=photo-1'
        ));
    }
}
