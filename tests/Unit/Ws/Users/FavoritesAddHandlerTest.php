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
use Piwigo\Ws\Users\FavoritesAddHandler;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Users\FavoritesAddHandler -- `pwg.users.favorites.add`.
 * Resolved via `Kernel::container()->get()`, same rationale as
 * GetListHandlerTest.php (Comments).
 *
 * Covers only the guest-denied 403 (its very first check). The
 * image-exists check and the real favorite write both need a real image
 * row and are not attempted here.
 */
function pwgUsersFavoritesAddHandlerTestSubject(): FavoritesAddHandler
{
    $handler = Kernel::container()->get(FavoritesAddHandler::class);
    if (! $handler instanceof FavoritesAddHandler) {
        throw new LogicException('Container returned an unexpected type for ' . FavoritesAddHandler::class);
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

test('returns a 403 WsErrorResponse when the user is a guest', function (): void {
    $handler = pwgUsersFavoritesAddHandlerTestSubject();

    $result = $handler([
        'image_id' => 1,
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403)
            ->and($result->message())
            ->toBe('User must be logged in.');
    }
});
