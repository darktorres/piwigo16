<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Ws\Groups\GetListHandler;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Groups\GetListHandler -- `pwg.groups.getList` (admin_only).
 * Resolved via `Kernel::container()->get()`, same rationale as
 * GetListHandlerTest.php (Comments).
 *
 * Covers its own pure "invalid order" regex guard (no DB access at all)
 * plus a real DB read against a deliberately non-existent `group_id` --
 * deterministic empty-result shape, no shared fixture dependency, same
 * B2-pattern real-DB approach as this campaign's Repository/Service
 * tests.
 */
function pwgGroupsGetListHandlerTestSubject(): GetListHandler
{
    $handler = Kernel::container()->get(GetListHandler::class);
    if (! $handler instanceof GetListHandler) {
        throw new LogicException('Container returned an unexpected type for ' . GetListHandler::class);
    }

    return $handler;
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
});

test('rejects a malformed order parameter', function (): void {
    $handler = pwgGroupsGetListHandlerTestSubject();

    $result = $handler([
        'per_page' => 10,
        'page' => 0,
        'order' => '!!!not-a-real-order!!!',
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(WsError::InvalidParam->value)
            ->and($result->message())
            ->toBe('Invalid input parameter order');
    }
});

test('returns an empty groups list for a group_id with no real matches', function (): void {
    $handler = pwgGroupsGetListHandlerTestSubject();

    $result = $handler([
        'group_id' => [999999],
        'per_page' => 10,
        'page' => 0,
        'order' => 'name',
    ]);

    expect($result)
        ->toBeArray();
    if (is_array($result)) {
        expect($result['groups']->content)->toBe([]);
    }
});
