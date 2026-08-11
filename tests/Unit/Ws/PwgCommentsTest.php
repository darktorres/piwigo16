<?php

declare(strict_types=1);

use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Piwigo\Ws\PwgComments;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;

/**
 * Piwigo\Ws\PwgComments -- the `pwg.userComments.*` WS methods (3
 * registrations, all admin_only). Resolved via
 * `Kernel::container()->get()` (same rationale as
 * `UpdatesSubControllerTest.php`) since `CommentService` alone has 11
 * further constructor deps none of the branches under test ever touch.
 * No dedicated Integration/Browser spec of its own.
 *
 * `getList()` covers its own 4 pure/config-driven validation guards, all
 * of which return before `commentService` is ever called: comments
 * disabled, an out-of-allowlist `status`, an out-of-allowlist
 * `per_page`, and an unparsable `f_min_date`/`f_max_date`. The real
 * comment listing needs real comment/image rows and is not attempted
 * here.
 *
 * `delete()`/`validate()` both cover their CSRF-token-mismatch 403
 * guard, same established pattern as `PwgPermissionsTest.php`.
 */
function pwgCommentsTestSubject(): PwgComments
{
    $ws = Kernel::container()->get(PwgComments::class);
    if (! $ws instanceof PwgComments) {
        throw new LogicException('Container returned an unexpected type for ' . PwgComments::class);
    }

    return $ws;
}

/**
 * None of the branches under test here ever reach `$service->invoke()`
 * -- a bare, unregistered PwgServer only needs to satisfy the by-ref
 * type, same rationale as `PwgPermissionsTest.php`'s own
 * pwgPermissionsTestServer() helper.
 */
function pwgCommentsTestServer(): PwgServer
{
    $currentConfig = new CurrentConfig();
    $currentUser = new CurrentUser($currentConfig);
    $currentUser->set(new User(
        id: UserId::from(1),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: UserStatus::Admin,
        enabledHigh: false,
    ));
    $accessControl = new AccessControl(
        HtmlServiceTestFactory::build(),
        new AccessControlTestFakeRedirectServiceNeverCalled(),
        new AccessLevelChecker($currentUser, $currentConfig),
    );

    return new PwgServer(new EventDispatcher(), $accessControl, new ApiKeyRequestFlag(), $currentConfig);
}

/**
 * @return array{status: string, search: string|null, f_min_date: string|null, f_max_date: string|null, page: int, per_page: int}
 */
function pwgCommentsTestBaseParams(): array
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

test('getList returns a 403 PwgError when comments are disabled', function (): void {
    CurrentConfigTestFactory::get()->activateComments = false;
    $ws = pwgCommentsTestSubject();
    $server = pwgCommentsTestServer();

    $result = $ws->getList(pwgCommentsTestBaseParams(), $server);

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(403)
            ->and($result->message())
            ->toBe('Comments are disabled');
    }
});

test('getList returns a 401 PwgError for a status outside the allowlist', function (): void {
    CurrentConfigTestFactory::get()->activateComments = true;
    $ws = pwgCommentsTestSubject();
    $server = pwgCommentsTestServer();

    $result = $ws->getList([
        ...pwgCommentsTestBaseParams(),
        'status' => 'not-a-real-status',
    ], $server);

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(401)
            ->and($result->message())
            ->toBe('Status must be: all, pending or validated');
    }
});

test('getList returns a 401 PwgError for a per_page outside the allowed set', function (): void {
    CurrentConfigTestFactory::get()->activateComments = true;
    $ws = pwgCommentsTestSubject();
    $server = pwgCommentsTestServer();

    $result = $ws->getList([
        ...pwgCommentsTestBaseParams(),
        'per_page' => 7,
    ], $server);

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(401)
            ->and($result->message())
            ->toBe('Per page must be: 5, 10, 25 or 50');
    }
});

test('getList returns a 401 PwgError for an unparsable f_min_date', function (): void {
    CurrentConfigTestFactory::get()->activateComments = true;
    $ws = pwgCommentsTestSubject();
    $server = pwgCommentsTestServer();

    $result = $ws->getList([
        ...pwgCommentsTestBaseParams(),
        'f_min_date' => 'not-a-real-date',
    ], $server);

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(401)
            ->and($result->message())
            ->toBe('Invalid f_min_date');
    }
});

test('getList returns a 401 PwgError for an unparsable f_max_date', function (): void {
    CurrentConfigTestFactory::get()->activateComments = true;
    $ws = pwgCommentsTestSubject();
    $server = pwgCommentsTestServer();

    $result = $ws->getList([
        ...pwgCommentsTestBaseParams(),
        'f_max_date' => 'not-a-real-date',
    ], $server);

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(401)
            ->and($result->message())
            ->toBe('Invalid f_max_date');
    }
});

test('delete returns a 403 PwgError when the submitted pwg_token does not match the real CSRF token', function (): void {
    $ws = pwgCommentsTestSubject();
    $server = pwgCommentsTestServer();

    $result = $ws->delete([
        'comment_id' => [1],
        'pwg_token' => 'wrong-token',
    ], $server);

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(403);
    }
});

test('validate returns a 403 PwgError when the submitted pwg_token does not match the real CSRF token', function (): void {
    $ws = pwgCommentsTestSubject();
    $server = pwgCommentsTestServer();

    $result = $ws->validate([
        'comment_id' => [1],
        'pwg_token' => 'wrong-token',
    ], $server);

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(403);
    }
});
