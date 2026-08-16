<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Piwigo\Ws\Users\FavoritesGetListHandler;

/**
 * Piwigo\Ws\Users\FavoritesGetListHandler -- `pwg.users.favorites.getList`.
 * Resolved via `Kernel::container()->get()`, same rationale as
 * GetListHandlerTest.php (Comments).
 *
 * Covers only the guest-denied `false` return (its very first check,
 * unlike its sibling favorites handlers this one returns a bare `false`
 * rather than a `WsErrorResponse`). The real favorites listing needs real
 * image rows and is not attempted here.
 */
function pwgUsersFavoritesGetListHandlerTestSubject(): FavoritesGetListHandler
{
    $handler = Kernel::container()->get(FavoritesGetListHandler::class);
    if (! $handler instanceof FavoritesGetListHandler) {
        throw new LogicException('Container returned an unexpected type for ' . FavoritesGetListHandler::class);
    }

    return $handler;
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from(2),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: UserStatus::Guest,
        enabledHigh: false,
    ));
});

afterEach(function (): void {
    Kernel::reset();
});

test('returns false when the user is a guest', function (): void {
    $handler = pwgUsersFavoritesGetListHandlerTestSubject();

    $result = $handler([
        'per_page' => 100,
        'page' => 0,
    ]);

    expect($result)
        ->toBeFalse();
});
