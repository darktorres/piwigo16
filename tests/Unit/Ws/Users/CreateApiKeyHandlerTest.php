<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Piwigo\Ws\Users\CreateApiKeyHandler;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Users\CreateApiKeyHandler -- `pwg.users.api_key.create`.
 * Resolved via `Kernel::container()->get()`, same rationale as
 * GetListHandlerTest.php (Comments).
 *
 * Covers only the guest-denied 401 (its very first check, `isAGuest() or
 * ! connectedWithPwgUi()` -- a guest user short-circuits before
 * `connectedWithPwgUi()` is ever evaluated). The real key creation needs a
 * real, non-guest, PwgUi-connected session and is not attempted here.
 */
function pwgUsersCreateApiKeyHandlerTestSubject(): CreateApiKeyHandler
{
    $handler = Kernel::container()->get(CreateApiKeyHandler::class);
    if (! $handler instanceof CreateApiKeyHandler) {
        throw new LogicException('Container returned an unexpected type for ' . CreateApiKeyHandler::class);
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

    $currentLogger = Kernel::container()->get(CurrentLogger::class);
    if (! $currentLogger instanceof CurrentLogger) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentLogger::class);
    }
    $currentLogger->set(new Logger([
        'severity' => Logger::OFF,
    ]));
});

afterEach(function (): void {
    Kernel::reset();
});

test('returns a 401 WsErrorResponse when the user is a guest', function (): void {
    $handler = pwgUsersCreateApiKeyHandlerTestSubject();

    $result = $handler([
        'key_name' => 'my key',
        'duration' => 30,
        'pwg_token' => 'anything',
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(401);
    }
});
