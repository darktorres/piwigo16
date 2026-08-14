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
use Piwigo\Ws\Images\SetInfoHandler;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Images\SetInfoHandler -- `pwg.images.setInfo` (admin_only).
 * Resolved via `Kernel::container()->get()`, same rationale as
 * GetListHandlerTest.php (Comments).
 *
 * Covers the CSRF-token-mismatch 403 guard, which fires before touching
 * any real service.
 */
function pwgImagesSetInfoHandlerTestSubject(): SetInfoHandler
{
    $handler = Kernel::container()->get(SetInfoHandler::class);
    if (! $handler instanceof SetInfoHandler) {
        throw new LogicException('Container returned an unexpected type for ' . SetInfoHandler::class);
    }

    return $handler;
}

function pwgImagesSetInfoHandlerTestServer(): Server
{
    $server = Kernel::container()->get(Server::class);
    if (! $server instanceof Server) {
        throw new LogicException('Container returned an unexpected type for ' . Server::class);
    }

    return $server;
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from(1),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: UserStatus::Admin,
        enabledHigh: false,
    ));
});

afterEach(function (): void {
    Kernel::reset();
});

test('setInfo returns a 403 WsErrorResponse when a submitted pwg_token does not match the real CSRF token', function (): void {
    $handler = pwgImagesSetInfoHandlerTestSubject();

    $result = $handler([
        'image_id' => 1,
        'file' => null,
        'name' => null,
        'author' => null,
        'date_creation' => null,
        'comment' => null,
        'categories' => null,
        'tag_ids' => null,
        'level' => null,
        'single_value_mode' => 'fill_if_empty',
        'multiple_value_mode' => 'append',
        'pwg_token' => 'wrong-token',
    ], pwgImagesSetInfoHandlerTestServer());

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});
