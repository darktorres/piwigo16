<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Ws\Permissions\GetListHandler;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Permissions\GetListHandler -- `pwg.permissions.getList`
 * (admin_only). Resolved via `Kernel::container()->get()`, same
 * rationale as GetListHandlerTest.php (Comments).
 *
 * Covers its own pure "too many parameters" guard (no DB access at all)
 * plus a real DB read against a deliberately non-existent `cat_id`
 * (999999) -- deterministic empty-result shape, no shared fixture
 * dependency, same B2-pattern real-DB approach as this campaign's
 * Repository/Service tests.
 */
function pwgPermissionsGetListHandlerTestSubject(): GetListHandler
{
    $handler = Kernel::container()->get(GetListHandler::class);
    if (! $handler instanceof GetListHandler) {
        throw new LogicException('Container returned an unexpected type for ' . GetListHandler::class);
    }

    return $handler;
}

/**
 * This handler never reaches `$service->invoke()` -- its "too many
 * parameters" guard fires first for the guard test, and the real-DB
 * test never needs a registered method either. A bare, unregistered
 * Server only needs to satisfy the type.
 */
beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
});

test('rejects more than one of cat_id/group_id/user_id at once', function (): void {
    $handler = pwgPermissionsGetListHandlerTestSubject();

    $result = $handler([
        'cat_id' => [1],
        'group_id' => [1],
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(WsError::InvalidParam->value)
            ->and($result->message())
            ->toBe('Too many parameters, provide cat_id OR user_id OR group_id');
    }
});

test('returns an empty categories list for a cat_id with no real access rows', function (): void {
    $handler = pwgPermissionsGetListHandlerTestSubject();

    $result = $handler([
        'cat_id' => [999999],
    ]);

    expect($result)
        ->toBeArray();
    if (is_array($result)) {
        expect($result['categories']->content)->toBe([]);
    }
});
