<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Ws\Users\SetMyInfoHandler;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Users\SetMyInfoHandler -- `pwg.users.setMyInfo`. Resolved via
 * `Kernel::container()->get()`, same rationale as GetListHandlerTest.php
 * (Comments).
 *
 * Covers both an explicitly wrong token and an entirely absent one, its
 * very first check (before even the guest guard) -- the real update needs
 * a real, non-guest user row and is not attempted here.
 */
function pwgUsersSetMyInfoHandlerTestSubject(): SetMyInfoHandler
{
    $handler = Kernel::container()->get(SetMyInfoHandler::class);
    if (! $handler instanceof SetMyInfoHandler) {
        throw new LogicException('Container returned an unexpected type for ' . SetMyInfoHandler::class);
    }

    return $handler;
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
});

test('returns a 403 WsErrorResponse when a submitted pwg_token does not match the real CSRF token', function (): void {
    $handler = pwgUsersSetMyInfoHandlerTestSubject();

    $result = $handler([
        'email' => 'me@example.test',
        'pwg_token' => 'wrong-token',
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});

test('returns a 403 WsErrorResponse when pwg_token is absent entirely', function (): void {
    $handler = pwgUsersSetMyInfoHandlerTestSubject();

    $result = $handler([
        'email' => 'me@example.test',
        'pwg_token' => null,
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});
