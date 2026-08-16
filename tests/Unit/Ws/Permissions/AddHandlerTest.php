<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Users\User;
use Piwigo\Ws\Permissions\AddHandler;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Permissions\AddHandler -- `pwg.permissions.add` (admin_only,
 * post_only). Resolved via `Kernel::container()->get()`, same rationale
 * as GetListHandlerTest.php (Comments).
 *
 * `AddHandler` checks `CsrfService::getToken() !== $input->pwgToken` as
 * its very first statement, before touching `categoryService`/
 * `permissionService` at all -- a wrong token is therefore a cheap,
 * DB-free 403 branch, same shape as this campaign's established
 * CSRF-mismatch pattern. Its real permission-grant logic needs real
 * category/group/user rows and is not attempted here.
 */
function pwgPermissionsAddHandlerTestSubject(): AddHandler
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

test('returns a 403 WsErrorResponse when the submitted pwg_token does not match the real CSRF token', function (): void {
    $handler = pwgPermissionsAddHandlerTestSubject();

    $result = $handler([
        'cat_id' => [1],
        'recursive' => false,
        'pwg_token' => 'wrong-token',
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403)
            ->and($result->message())
            ->toBe('Invalid security token');
    }
});
