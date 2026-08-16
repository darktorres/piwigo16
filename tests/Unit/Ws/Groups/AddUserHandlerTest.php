<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Users\User;
use Piwigo\Ws\Groups\AddUserHandler;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Groups\AddUserHandler -- `pwg.groups.addUser` (admin_only,
 * post_only). Resolved via `Kernel::container()->get()`, same rationale
 * as GetListHandlerTest.php (Comments).
 *
 * Checks `CsrfService::getToken() !== $input->pwgToken` as its very
 * first statement, before touching `groupService` at all -- a wrong
 * token is therefore a cheap, DB-free 403 branch. The real membership
 * add needs real group/user rows and is not attempted here.
 */
function pwgGroupsAddUserHandlerTestSubject(): AddUserHandler
{
    $handler = Kernel::container()->get(AddUserHandler::class);
    if (! $handler instanceof AddUserHandler) {
        throw new LogicException('Container returned an unexpected type for ' . AddUserHandler::class);
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
    $handler = pwgGroupsAddUserHandlerTestSubject();

    $result = $handler([
        'group_id' => 1,
        'user_id' => [1],
        'pwg_token' => 'wrong-token',
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});
