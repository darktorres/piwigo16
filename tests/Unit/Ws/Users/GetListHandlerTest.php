<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Ws\Users\GetListHandler;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Users\GetListHandler -- `pwg.users.getList`. Resolved via
 * `Kernel::container()->get()`.
 *
 * Unlike most other Users handlers, this one has no CSRF/guest guard --
 * it's a read-only query. Covers its 4 pure, DB-free input-validation
 * branches (order/min_register/max_register/min_level, each checked
 * before any DB call). `filter` is deliberately left absent in every
 * case: setting it would reach `GroupService::getIdsByNameLike()`, a real
 * DB call this file doesn't attempt. The real paginated listing itself
 * needs `UserService::getListForWs()` against real user rows and is not
 * attempted here either.
 */
function pwgUsersGetListHandlerTestSubject(): GetListHandler
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

test('returns an InvalidParam WsErrorResponse for a malformed order', function (): void {
    $handler = pwgUsersGetListHandlerTestSubject();

    $result = $handler([
        'order' => '123',
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(1003)
            ->and($result->message())
            ->toBe('Invalid input parameter order');
    }
});

test('returns an InvalidParam WsErrorResponse for a malformed min_register', function (): void {
    $handler = pwgUsersGetListHandlerTestSubject();

    $result = $handler([
        'min_register' => 'not-a-date',
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(1003)
            ->and($result->message())
            ->toBe('Invalid input parameter min_register');
    }
});

test('returns an InvalidParam WsErrorResponse for a malformed max_register', function (): void {
    $handler = pwgUsersGetListHandlerTestSubject();

    $result = $handler([
        'max_register' => 'not-a-date',
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(1003)
            ->and($result->message())
            ->toBe('Invalid input parameter max_register');
    }
});

test('returns an InvalidParam WsErrorResponse for a min_level outside the available permission levels', function (): void {
    $handler = pwgUsersGetListHandlerTestSubject();

    $result = $handler([
        'min_level' => 99,
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(1003)
            ->and($result->message())
            ->toBe('Invalid level');
    }
});
