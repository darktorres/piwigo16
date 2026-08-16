<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Ws\Tags\DeleteHandler;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Tags\DeleteHandler -- `pwg.tags.delete` (admin_only).
 * Resolved via `Kernel::container()->get()`, same rationale as
 * GetListHandlerTest.php (Comments).
 *
 * Checks `CsrfService::getToken() !== $input->pwgToken` as its very
 * first statement, before touching `tagService` at all -- a wrong token
 * is therefore a cheap, DB-free 403 branch, same established pattern as
 * PermissionsTest.php/GroupsTest.php. The real tag deletion needs real
 * tag rows and is not attempted here.
 */
function pwgTagsDeleteHandlerTestSubject(): DeleteHandler
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

test('returns a 403 WsErrorResponse when the submitted pwg_token does not match the real CSRF token', function (): void {
    $handler = pwgTagsDeleteHandlerTestSubject();

    $result = $handler([
        'tag_id' => [1],
        'pwg_token' => 'wrong-token',
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});
