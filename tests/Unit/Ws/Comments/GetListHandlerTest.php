<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Ws\Comments\GetListHandler;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Comments\GetListHandler -- `pwg.userComments.getList`
 * (admin_only). Resolved via `Kernel::container()->get()` (same
 * rationale as `UpdatesSubControllerTest.php`) since `CommentService`
 * alone has 11 further constructor deps none of the branches under test
 * ever touch. No dedicated Integration/Browser spec of its own.
 *
 * Covers its own 4 pure/config-driven validation guards, all of which
 * return before `commentService` is ever called: comments disabled, an
 * out-of-allowlist `status`, an out-of-allowlist `per_page`, and an
 * unparsable `f_min_date`/`f_max_date`. The real comment listing needs
 * real comment/image rows and is not attempted here.
 */
function pwgGetListHandlerTestSubject(): GetListHandler
{
    $handler = Kernel::container()->get(GetListHandler::class);
    if (! $handler instanceof GetListHandler) {
        throw new LogicException('Container returned an unexpected type for ' . GetListHandler::class);
    }

    return $handler;
}

/**
 * This handler never reaches `$service->invoke()` -- a bare,
 * unregistered Server only needs to satisfy the type, same rationale as
 * PermissionsTest.php's own pwgPermissionsTestServer() helper.
 */
/**
 * @return array{status: string, search: string|null, f_min_date: string|null, f_max_date: string|null, page: int, per_page: int}
 */
function pwgGetListHandlerTestBaseParams(): array
{
    return [
        'status' => 'all',
        'search' => null,
        'f_min_date' => null,
        'f_max_date' => null,
        'page' => 0,
        'per_page' => 10,
    ];
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
    Kernel::reset();
});

test('returns a 403 WsErrorResponse when comments are disabled', function (): void {
    CurrentConfigTestFactory::get()->activateComments = false;
    $handler = pwgGetListHandlerTestSubject();

    $result = $handler(pwgGetListHandlerTestBaseParams());

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403)
            ->and($result->message())
            ->toBe('Comments are disabled');
    }
});

test('returns a 401 WsErrorResponse for a status outside the allowlist', function (): void {
    CurrentConfigTestFactory::get()->activateComments = true;
    $handler = pwgGetListHandlerTestSubject();

    $result = $handler([
        ...pwgGetListHandlerTestBaseParams(),
        'status' => 'not-a-real-status',
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(401)
            ->and($result->message())
            ->toBe('Status must be: all, pending or validated');
    }
});

test('returns a 401 WsErrorResponse for a per_page outside the allowed set', function (): void {
    CurrentConfigTestFactory::get()->activateComments = true;
    $handler = pwgGetListHandlerTestSubject();

    $result = $handler([
        ...pwgGetListHandlerTestBaseParams(),
        'per_page' => 7,
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(401)
            ->and($result->message())
            ->toBe('Per page must be: 5, 10, 25 or 50');
    }
});

test('returns a 401 WsErrorResponse for an unparsable f_min_date', function (): void {
    CurrentConfigTestFactory::get()->activateComments = true;
    $handler = pwgGetListHandlerTestSubject();

    $result = $handler([
        ...pwgGetListHandlerTestBaseParams(),
        'f_min_date' => 'not-a-real-date',
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(401)
            ->and($result->message())
            ->toBe('Invalid f_min_date');
    }
});

test('returns a 401 WsErrorResponse for an unparsable f_max_date', function (): void {
    CurrentConfigTestFactory::get()->activateComments = true;
    $handler = pwgGetListHandlerTestSubject();

    $result = $handler([
        ...pwgGetListHandlerTestBaseParams(),
        'f_max_date' => 'not-a-real-date',
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(401)
            ->and($result->message())
            ->toBe('Invalid f_max_date');
    }
});
