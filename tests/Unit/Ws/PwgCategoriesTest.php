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
use Piwigo\Core\WsError;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Piwigo\Ws\PwgCategories;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\Server;

/**
 * Piwigo\Ws\PwgCategories -- the `pwg.categories.*` WS methods (13 public
 * methods). Resolved via `Kernel::container()->get()` (same rationale
 * as `UpdatesSubControllerTest.php`) -- 14 constructor deps, none
 * touched by the guard branches under test. No dedicated Integration/
 * Browser spec of its own.
 *
 * `getList()` covers its own 2 pure validation guards (invalid
 * `thumbnail_size`, incompatible `recursive`+`limit`), both reached
 * after `$this->currentUser->get()` (needs a real, initialized
 * `CurrentUser`) but before any real category tree computation.
 * `getImages()` covers its "cat_id not found" 404 guard via a real DB
 * read against a deliberately non-existent `cat_id`, same B2-pattern
 * approach as this campaign's Repository/Service tests. `add()`/
 * `setInfo()`/`delete()`/`move()` each cover their CSRF-token-mismatch
 * 403 guard (`add()`/`setInfo()`'s own check only fires when `pwg_token`
 * is present at all -- both are included here with a wrong token
 * present, not absent).
 *
 * The real category CRUD logic behind every guard, plus
 * `getAdminList()`/`setRank()`/`setRepresentative()`/
 * `deleteRepresentative()`/`refreshRepresentative()`/
 * `calculateOrphans()` (none of which have a CSRF/pure guard of their
 * own -- confirmed by reading each), are not attempted here.
 */
function pwgCategoriesTestSubject(): PwgCategories
{
    $ws = Kernel::container()->get(PwgCategories::class);
    if (! $ws instanceof PwgCategories) {
        throw new LogicException('Container returned an unexpected type for ' . PwgCategories::class);
    }

    return $ws;
}

/**
 * None of the branches under test here ever reach `$service->invoke()`
 * -- a bare, unregistered Server only needs to satisfy the by-ref
 * type, same rationale as `PwgPermissionsTest.php`'s own
 * pwgPermissionsTestServer() helper.
 */
function pwgCategoriesTestServer(): Server
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

    return new Server(new EventDispatcher(), $accessControl, new ApiKeyRequestFlag(), $currentConfig);
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from(1),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: UserStatus::Admin,
        enabledHigh: false,
    ));
});

afterEach(function (): void {
    Kernel::reset();
});

test('getList rejects an unknown thumbnail_size', function (): void {
    $ws = pwgCategoriesTestSubject();
    $server = pwgCategoriesTestServer();

    $result = $ws->getList([
        'cat_id' => null,
        'recursive' => false,
        'public' => false,
        'tree_output' => false,
        'fullname' => false,
        'thumbnail_size' => 'not-a-real-size',
        'search' => null,
        'limit' => null,
    ], $server);

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(WsError::INVALID_PARAM)
            ->and($result->message())
            ->toBe('Invalid thumbnail_size');
    }
});

test('getList rejects recursive combined with a non-null limit', function (): void {
    $ws = pwgCategoriesTestSubject();
    $server = pwgCategoriesTestServer();

    $result = $ws->getList([
        'cat_id' => null,
        'recursive' => true,
        'public' => false,
        'tree_output' => false,
        'fullname' => false,
        'thumbnail_size' => 'medium',
        'search' => null,
        'limit' => 5,
    ], $server);

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(WsError::INVALID_PARAM)
            ->and($result->message())
            ->toBe('Cannot use both recursive and limit parameters at the same time');
    }
});

test('getImages returns a 404 PwgError for a cat_id with no real match', function (): void {
    $ws = pwgCategoriesTestSubject();
    $server = pwgCategoriesTestServer();

    $result = $ws->getImages([
        'cat_id' => [999999],
        'recursive' => false,
        'per_page' => 10,
        'page' => 0,
        'order' => null,
        'f_min_rate' => null,
        'f_max_rate' => null,
        'f_min_hit' => null,
        'f_max_hit' => null,
        'f_min_ratio' => null,
        'f_max_ratio' => null,
        'f_max_level' => null,
        'f_min_date_available' => null,
        'f_max_date_available' => null,
        'f_min_date_created' => null,
        'f_max_date_created' => null,
    ], $server);

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(404)
            ->and($result->message())
            ->toBe('cat_id {999999} not found');
    }
});

test('add returns a 403 PwgError when a submitted pwg_token does not match the real CSRF token', function (): void {
    $ws = pwgCategoriesTestSubject();
    $server = pwgCategoriesTestServer();

    $result = $ws->add([
        'name' => 'New album',
        'parent' => null,
        'comment' => null,
        'visible' => true,
        'status' => null,
        'commentable' => true,
        'position' => null,
        'pwg_token' => 'wrong-token',
    ], $server);

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(403);
    }
});

test('setInfo returns a 403 PwgError when a submitted pwg_token does not match the real CSRF token', function (): void {
    $ws = pwgCategoriesTestSubject();
    $server = pwgCategoriesTestServer();

    $result = $ws->setInfo([
        'category_id' => 1,
        'name' => null,
        'comment' => null,
        'status' => null,
        'visible' => null,
        'commentable' => null,
        'apply_commentable_to_subalbums' => null,
        'pwg_token' => 'wrong-token',
    ], $server);

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(403);
    }
});

test('delete returns a 403 PwgError when the submitted pwg_token does not match the real CSRF token', function (): void {
    $ws = pwgCategoriesTestSubject();
    $server = pwgCategoriesTestServer();

    $result = $ws->delete([
        'category_id' => '1',
        'photo_deletion_mode' => 'no_delete',
        'pwg_token' => 'wrong-token',
    ], $server);

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(403);
    }
});

test('move returns a 403 PwgError when the submitted pwg_token does not match the real CSRF token', function (): void {
    $ws = pwgCategoriesTestSubject();
    $server = pwgCategoriesTestServer();

    $result = $ws->move([
        'category_id' => '1',
        'parent' => 0,
        'pwg_token' => 'wrong-token',
    ], $server);

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(403);
    }
});
