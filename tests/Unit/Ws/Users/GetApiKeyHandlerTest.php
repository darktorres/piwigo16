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
use Piwigo\Ws\Users\GetApiKeyHandler;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Users\GetApiKeyHandler -- `pwg.users.api_key.get`. Resolved
 * via `Kernel::container()->get()`, same rationale as GetListHandlerTest.php
 * (Comments).
 *
 * Covers only the guest-denied 401 (its very first check). The real key
 * listing needs a real, non-guest, PwgUi-connected session and is not
 * attempted here.
 */
function pwgUsersGetApiKeyHandlerTestSubject(): GetApiKeyHandler
{
    $handler = Kernel::container()->get(GetApiKeyHandler::class);
    if (! $handler instanceof GetApiKeyHandler) {
        throw new LogicException('Container returned an unexpected type for ' . GetApiKeyHandler::class);
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

test('returns a 401 WsErrorResponse when the user is a guest', function (): void {
    $handler = pwgUsersGetApiKeyHandlerTestSubject();

    $result = $handler([
        'pwg_token' => 'anything',
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(401);
    }
});
