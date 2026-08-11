<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Bootstrap;

use PHPUnit\Framework\TestCase;
use Piwigo\Bootstrap\UserBootstrap;

/**
 * UserBootstrap::resolveApacheRemoteUser() resolves REMOTE_USER/
 * REDIRECT_REMOTE_USER. Pure function, no DB/globals needed.
 */
final class UserBootstrapResolveApacheRemoteUserTest extends TestCase
{
    public function testPrefersRemoteUserWhenBothArePresent(): void
    {
        $result = UserBootstrap::resolveApacheRemoteUser([
            'REMOTE_USER' => 'alice',
            'REDIRECT_REMOTE_USER' => 'bob',
        ]);

        self::assertSame('alice', $result);
    }

    public function testFallsBackToRedirectRemoteUser(): void
    {
        $result = UserBootstrap::resolveApacheRemoteUser([
            'REDIRECT_REMOTE_USER' => 'bob',
        ]);

        self::assertSame('bob', $result);
    }

    public function testReturnsNullWhenNeitherIsPresent(): void
    {
        $result = UserBootstrap::resolveApacheRemoteUser([
            'SOME_OTHER_KEY' => 'value',
        ]);

        self::assertNull($result);
    }

    public function testReturnsNullWhenRemoteUserIsNotAString(): void
    {
        $result = UserBootstrap::resolveApacheRemoteUser([
            'REMOTE_USER' => ['not', 'a', 'string'],
            'REDIRECT_REMOTE_USER' => 'bob',
        ]);

        self::assertSame('bob', $result);
    }
}
