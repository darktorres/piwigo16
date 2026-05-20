<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Users;

use PHPUnit\Framework\TestCase;
use Piwigo\Common\Enum\UserStatus;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;

final class CurrentUserTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        CurrentUser::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        CurrentUser::reset();
        unset($GLOBALS['user']);
    }

    public function testNotInitializedByDefault(): void
    {
        self::assertFalse(CurrentUser::isInitialized());
    }

    public function testGetBeforeInitThrows(): void
    {
        $this->expectException(\LogicException::class);
        CurrentUser::get();
    }

    public function testSetAndGet(): void
    {
        $user = new User(1, 'admin', 'admin@example.com', 'en_US', 'elegant', UserStatus::Webmaster, true);
        CurrentUser::set($user);
        self::assertTrue(CurrentUser::isInitialized());
        self::assertSame($user, CurrentUser::get());
    }

    public function testResetClearsInstance(): void
    {
        $user = new User(1, 'admin', 'admin@example.com', 'en_US', 'elegant', UserStatus::Webmaster, true);
        CurrentUser::set($user);
        CurrentUser::reset();
        self::assertFalse(CurrentUser::isInitialized());
    }

    public function testAttachGlobalsCreatesDefaultGuest(): void
    {
        // attachGlobals() no longer reads $GLOBALS['user'] — it creates a default guest.
        // The real user is set by UserBootstrap via CurrentUser::set().
        CurrentUser::attachGlobals();
        self::assertTrue(CurrentUser::isInitialized());
        self::assertSame(UserStatus::Guest, CurrentUser::get()->status);
    }

    public function testAttachGlobalsIsIdempotent(): void
    {
        // calling twice does not override an already-set instance
        CurrentUser::attachGlobals();
        $first = CurrentUser::get();
        CurrentUser::attachGlobals();
        self::assertSame($first, CurrentUser::get());
    }
}
