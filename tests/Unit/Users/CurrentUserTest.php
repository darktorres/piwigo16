<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Users;

use PHPUnit\Framework\TestCase;
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
        $user = new User(1, 'admin', 'admin@example.com', 'en_US', 'elegant', 'webmaster', true);
        CurrentUser::set($user);
        self::assertTrue(CurrentUser::isInitialized());
        self::assertSame($user, CurrentUser::get());
    }

    public function testResetClearsInstance(): void
    {
        $user = new User(1, 'admin', 'admin@example.com', 'en_US', 'elegant', 'webmaster', true);
        CurrentUser::set($user);
        CurrentUser::reset();
        self::assertFalse(CurrentUser::isInitialized());
    }

    public function testAttachGlobalsWrapsUserArray(): void
    {
        $GLOBALS['user'] = [
            'id'       => 5,
            'username' => 'bob',
            'email'    => 'bob@example.com',
            'language' => 'de_DE',
            'theme'    => 'modus',
            'status'   => 'normal',
        ];
        CurrentUser::attachGlobals();
        self::assertTrue(CurrentUser::isInitialized());
        self::assertSame('bob', CurrentUser::get()->username);
        self::assertSame(5, CurrentUser::get()->id);
    }

    public function testAttachGlobalsMissingUserArrayGivesGuest(): void
    {
        unset($GLOBALS['user']);
        CurrentUser::attachGlobals();
        self::assertSame('guest', CurrentUser::get()->status);
    }
}
