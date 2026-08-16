<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Ws\Comments\DeleteHandler;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Comments\DeleteHandler -- `pwg.userComments.delete`
 * (admin_only, post_only). Resolved via `Kernel::container()->get()`,
 * same rationale as GetListHandlerTest.php. Covers only the
 * CSRF-token-mismatch 403 guard, same established pattern as
 * PermissionsTest.php -- a real delete needs a real comment row and is
 * not attempted here.
 */
function pwgDeleteHandlerTestSubject(): DeleteHandler
{
    $handler = Kernel::container()->get(DeleteHandler::class);
    if (! $handler instanceof DeleteHandler) {
        throw new LogicException('Container returned an unexpected type for ' . DeleteHandler::class);
    }

    return $handler;
}

/**
 * This handler never reaches `$service->invoke()` -- a bare,
 * unregistered Server only needs to satisfy the type, same rationale as
 * PermissionsTest.php's own pwgPermissionsTestServer() helper.
 */
beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
});

test('returns a 403 WsErrorResponse when the submitted pwg_token does not match the real CSRF token', function (): void {
    $handler = pwgDeleteHandlerTestSubject();

    $result = $handler([
        'comment_id' => [1],
        'pwg_token' => 'wrong-token',
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});
