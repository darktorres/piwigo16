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
use Piwigo\Ws\Extensions\IgnoreUpdateHandler;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Extensions\IgnoreUpdateHandler --
 * `pwg.extensions.ignoreUpdate` (admin_only, webmaster-only). Resolved
 * via `Kernel::container()->get()`, same rationale as
 * PluginsPerformActionHandlerTest.php.
 *
 * Covers the "not webmaster" 401 (its very first check) and, separately,
 * the CSRF-mismatch 403 (reached with a real webmaster user) -- both
 * avoid ever reaching `ExtensionUpdateChecker::ignoreUpdate()`/
 * `resetIgnoredForType()` (real PEM/session state writes).
 */
function pwgIgnoreUpdateHandlerTestSubject(): IgnoreUpdateHandler
{
    $handler = Kernel::container()->get(IgnoreUpdateHandler::class);
    if (! $handler instanceof IgnoreUpdateHandler) {
        throw new LogicException('Container returned an unexpected type for ' . IgnoreUpdateHandler::class);
    }

    return $handler;
}

function pwgIgnoreUpdateHandlerTestSetUser(bool $isWebmaster): void
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

test('returns a 401 WsErrorResponse when the user is not a webmaster', function (): void {
    pwgIgnoreUpdateHandlerTestSetUser(isWebmaster: false);

    $handler = pwgIgnoreUpdateHandlerTestSubject();
    $server = Kernel::container()->get(Server::class);
    if (! $server instanceof Server) {
        throw new LogicException('Container returned an unexpected type for ' . Server::class);
    }

    $result = $handler([
        'type' => null,
        'id' => null,
        'reset' => false,
        'pwg_token' => 'anything',
    ], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(401)
            ->and($result->message())
            ->toBe('Access denied');
    }
});

test('returns a 403 WsErrorResponse when the submitted pwg_token does not match the real CSRF token, for a real webmaster', function (): void {
    pwgIgnoreUpdateHandlerTestSetUser(isWebmaster: true);

    $handler = pwgIgnoreUpdateHandlerTestSubject();
    $server = Kernel::container()->get(Server::class);
    if (! $server instanceof Server) {
        throw new LogicException('Container returned an unexpected type for ' . Server::class);
    }

    $result = $handler([
        'type' => null,
        'id' => null,
        'reset' => false,
        'pwg_token' => 'wrong-token',
    ], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});
