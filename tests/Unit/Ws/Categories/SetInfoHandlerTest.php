<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Ws\Categories\SetInfoHandler;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Categories\SetInfoHandler -- `pwg.categories.setInfo`
 * (admin_only, post_only). Resolved via `Kernel::container()->get()`,
 * same rationale as GetListHandlerTest.php (Comments).
 *
 * Covers both an explicitly wrong token and an entirely absent one (SEC
 * finding 5 -- checkSecurityToken() used to be called with
 * required: false here, so an omitted pwg_token skipped CSRF validation
 * entirely). The real category update needs a real category row and is
 * not attempted here.
 */
function pwgCategoriesSetInfoHandlerTestSubject(): SetInfoHandler
{
    $handler = Kernel::container()->get(SetInfoHandler::class);
    if (! $handler instanceof SetInfoHandler) {
        throw new LogicException('Container returned an unexpected type for ' . SetInfoHandler::class);
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
    $handler = pwgCategoriesSetInfoHandlerTestSubject();

    $result = $handler([
        'category_id' => 1,
        'name' => null,
        'comment' => null,
        'status' => null,
        'visible' => null,
        'commentable' => null,
        'apply_commentable_to_subalbums' => null,
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
    $handler = pwgCategoriesSetInfoHandlerTestSubject();

    $result = $handler([
        'category_id' => 1,
        'name' => null,
        'comment' => null,
        'status' => null,
        'visible' => null,
        'commentable' => null,
        'apply_commentable_to_subalbums' => null,
        'pwg_token' => null,
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});
