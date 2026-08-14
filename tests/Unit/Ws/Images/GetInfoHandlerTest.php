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
use Piwigo\Ws\Images\GetInfoHandler;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Images\GetInfoHandler -- `pwg.images.getInfo`. Resolved via
 * `Kernel::container()->get()`, same rationale as GetListHandlerTest.php
 * (Comments).
 *
 * Covers the "image_id not found" 404 guard via a real DB read against a
 * deliberately non-existent image_id, same B2-pattern approach as this
 * campaign's Repository/Service tests.
 */
function pwgImagesGetInfoHandlerTestSubject(): GetInfoHandler
{
    $handler = Kernel::container()->get(GetInfoHandler::class);
    if (! $handler instanceof GetInfoHandler) {
        throw new LogicException('Container returned an unexpected type for ' . GetInfoHandler::class);
    }

    return $handler;
}

function pwgImagesGetInfoHandlerTestServer(): Server
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

test('getInfo returns a 404 WsErrorResponse for an image_id with no real match', function (): void {
    $handler = pwgImagesGetInfoHandlerTestSubject();

    $result = $handler([
        'image_id' => 999999,
        'comments_page' => 0,
        'comments_per_page' => 10,
    ], pwgImagesGetInfoHandlerTestServer());

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(404)
            ->and($result->message())
            ->toBe('image_id not found');
    }
});
