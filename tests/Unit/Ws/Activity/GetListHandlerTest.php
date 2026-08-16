<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\UserId;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Ws\Activity\GetListHandler;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Activity\GetListHandler -- `pwg.activity.getList` (admin_only).
 * Resolved via `Kernel::container()->get()`, same rationale as
 * GetListHandlerTest.php (Comments).
 *
 * Covers the pure "invalid date_min" guard and the present-but-empty
 * uid/id regression (both reachable without any real ActivityService/DB
 * call beyond the paginated read itself).
 */
function pwgActivityGetListHandlerTestSubject(): GetListHandler
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

test('getList rejects an unparsable date_min', function (): void {
    $handler = pwgActivityGetListHandlerTestSubject();

    $result = $handler([
        'page' => null,
        'offset' => 0,
        'uid' => null,
        'date_min' => 'not-a-real-date',
        'date_max' => null,
        'id' => null,
        'object' => null,
        'action' => null,
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(WsError::InvalidParam->value)
            ->and($result->message())
            ->toBe('Invalid date_min');
    }
});

test('getList treats a present-but-empty uid/id the same as absent, not a fatal type error', function (): void {
    // Both are WsParamType::ID (optional, null default) --
    // Server::checkType() deliberately skips type coercion for an
    // empty-string value on an OPTIONAL param, so a real client that
    // sends 'uid='/'id=' (e.g. Users\Admin\user_activity.js's own
    // uid_filter/additional_filt_value, undefined/null on first page
    // load) reaches this method with the raw string '', not int|null.
    // Real bug reproduced live: this previously threw
    // "UserId::from(): Argument #1 ($value) must be of type int, string
    // given" / "ActivityListCriteria::__construct(): Argument #6
    // ($objectId) must be of type ?int, string given" instead of
    // returning a result.
    $handler = pwgActivityGetListHandlerTestSubject();

    $result = $handler([
        'page' => 0,
        'offset' => 0,
        'uid' => '',
        'date_min' => null,
        'date_max' => null,
        'id' => '',
        'object' => null,
        'action' => null,
    ]);

    expect($result)
        ->not->toBeInstanceOf(WsErrorResponse::class)
        ->toHaveKey('result_lines');
});
