<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Ws\Users\DeleteHandler;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Users\DeleteHandler -- `pwg.users.delete` (admin_only).
 * Resolved via `Kernel::container()->get()`, same rationale as
 * GetListHandlerTest.php (Comments).
 *
 * Covers both an explicitly wrong token and an entirely absent one -- the
 * real deletion needs real user rows and is not attempted here.
 */
function pwgUsersDeleteHandlerTestSubject(): DeleteHandler
{
    $handler = Kernel::container()->get(DeleteHandler::class);
    if (! $handler instanceof DeleteHandler) {
        throw new LogicException('Container returned an unexpected type for ' . DeleteHandler::class);
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
    $handler = pwgUsersDeleteHandlerTestSubject();

    $result = $handler([
        'user_id' => [999999],
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
    $handler = pwgUsersDeleteHandlerTestSubject();

    $result = $handler([
        'user_id' => [999999],
        'pwg_token' => null,
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});
