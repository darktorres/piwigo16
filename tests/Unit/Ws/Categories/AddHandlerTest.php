<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Ws\Categories\AddHandler;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Categories\AddHandler -- `pwg.categories.add` (admin_only).
 * Resolved via `Kernel::container()->get()`, same rationale as
 * GetListHandlerTest.php (Comments).
 *
 * Covers both an explicitly wrong token and an entirely absent one (SEC
 * finding 5 -- checkSecurityToken() used to be called with
 * required: false here, so an omitted pwg_token skipped CSRF validation
 * entirely). The real category creation needs a real DB write and is not
 * attempted here.
 */
function pwgCategoriesAddHandlerTestSubject(): AddHandler
{
    $handler = Kernel::container()->get(AddHandler::class);
    if (! $handler instanceof AddHandler) {
        throw new LogicException('Container returned an unexpected type for ' . AddHandler::class);
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
    $handler = pwgCategoriesAddHandlerTestSubject();

    $result = $handler([
        'name' => 'New album',
        'parent' => null,
        'comment' => null,
        'visible' => true,
        'status' => null,
        'commentable' => true,
        'position' => null,
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
    $handler = pwgCategoriesAddHandlerTestSubject();

    $result = $handler([
        'name' => 'New album',
        'parent' => null,
        'comment' => null,
        'visible' => true,
        'status' => null,
        'commentable' => true,
        'position' => null,
        'pwg_token' => null,
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});
