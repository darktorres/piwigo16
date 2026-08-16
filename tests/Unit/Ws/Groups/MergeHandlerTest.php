<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Ws\Groups\MergeHandler;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Groups\MergeHandler -- `pwg.groups.merge` (admin_only,
 * post_only). Resolved via `Kernel::container()->get()`, same rationale
 * as GetListHandlerTest.php (Comments).
 *
 * Checks `CsrfService::getToken() !== $input->pwgToken` as its very
 * first statement, before touching `groupService` or calling
 * `$server->invoke('pwg.groups.getList', ...)` at all -- a wrong token
 * is therefore a cheap, DB-free 403 branch. The real merge needs real
 * group rows and is not attempted here.
 */
function pwgGroupsMergeHandlerTestSubject(): MergeHandler
{
    $handler = Kernel::container()->get(MergeHandler::class);
    if (! $handler instanceof MergeHandler) {
        throw new LogicException('Container returned an unexpected type for ' . MergeHandler::class);
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
    $handler = pwgGroupsMergeHandlerTestSubject();

    $result = $handler([
        'destination_group_id' => 1,
        'merge_group_id' => [2],
        'pwg_token' => 'wrong-token',
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});
