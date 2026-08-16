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
use Piwigo\Ws\Users\SetMainUserHandler;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Users\SetMainUserHandler -- `pwg.users.setMainUser`
 * (admin_only, webmaster-only). Resolved via `Kernel::container()->get()`,
 * same rationale as GetListHandlerTest.php (Comments).
 *
 * Covers the "not webmaster" 403 (its very first check) and, separately,
 * the CSRF-mismatch 403 (reached with a real webmaster user) -- the real
 * user lookup/config write both need a real DB and are not attempted
 * here.
 */
function pwgUsersSetMainUserHandlerTestSubject(): SetMainUserHandler
{
    $handler = Kernel::container()->get(SetMainUserHandler::class);
    if (! $handler instanceof SetMainUserHandler) {
        throw new LogicException('Container returned an unexpected type for ' . SetMainUserHandler::class);
    }

    return $handler;
}

function pwgUsersSetMainUserHandlerTestSetUser(bool $isWebmaster): void
{
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from($isWebmaster ? 1 : 2),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: $isWebmaster ? UserStatus::Webmaster : UserStatus::Admin,
        enabledHigh: false,
    ));
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
});

test('returns a 403 WsErrorResponse when the user is not a webmaster', function (): void {
    pwgUsersSetMainUserHandlerTestSetUser(isWebmaster: false);

    $handler = pwgUsersSetMainUserHandlerTestSubject();

    $result = $handler([
        'user_id' => 999999,
        'pwg_token' => 'anything',
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403)
            ->and($result->message())
            ->toBe('You cannot perform this action');
    }
});

test('returns a 403 WsErrorResponse when the submitted pwg_token does not match the real CSRF token, for a real webmaster', function (): void {
    pwgUsersSetMainUserHandlerTestSetUser(isWebmaster: true);

    $handler = pwgUsersSetMainUserHandlerTestSubject();

    $result = $handler([
        'user_id' => 999999,
        'pwg_token' => 'wrong-token',
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});
