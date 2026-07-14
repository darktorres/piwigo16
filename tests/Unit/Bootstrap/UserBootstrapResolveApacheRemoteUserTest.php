<?php

declare(strict_types=1);

use Piwigo\Bootstrap\UserBootstrap;

/**
 * UserBootstrap::resolveApacheRemoteUser() -- the REMOTE_USER/
 * REDIRECT_REMOTE_USER resolution loop extracted from
 * include/user.inc.php during its P23 batch 5 port. Pure function, no
 * DB/globals needed.
 */
final class UserBootstrapResolveApacheRemoteUserTest extends \PHPUnit\Framework\TestCase
{
    public function test_prefers_remote_user_when_both_are_present(): void
    {
        $result = UserBootstrap::resolveApacheRemoteUser([
            'REMOTE_USER' => 'alice',
            'REDIRECT_REMOTE_USER' => 'bob',
        ]);

        self::assertSame('alice', $result);
    }

    public function test_falls_back_to_redirect_remote_user(): void
    {
        $result = UserBootstrap::resolveApacheRemoteUser([
            'REDIRECT_REMOTE_USER' => 'bob',
        ]);

        self::assertSame('bob', $result);
    }

    public function test_returns_null_when_neither_is_present(): void
    {
        $result = UserBootstrap::resolveApacheRemoteUser([
            'SOME_OTHER_KEY' => 'value',
        ]);

        self::assertNull($result);
    }

    public function test_returns_null_when_remote_user_is_not_a_string(): void
    {
        $result = UserBootstrap::resolveApacheRemoteUser([
            'REMOTE_USER' => ['not', 'a', 'string'],
            'REDIRECT_REMOTE_USER' => 'bob',
        ]);

        self::assertSame('bob', $result);
    }
}
