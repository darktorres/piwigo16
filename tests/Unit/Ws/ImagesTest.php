<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Piwigo\Ws\Images;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Images -- the remaining, not-yet-migrated `pwg.images.*` WS
 * methods (Group 19's Images batch is porting these one method at a
 * time onto Ws/Images/{Method}Handler classes -- see e.g.
 * tests/Unit/Ws/Images/GetInfoHandlerTest.php for a migrated method's
 * own test file). Resolved via `Kernel::container()->get()` (same
 * rationale as `UpdatesSubControllerTest.php`), none of the remaining
 * constructor deps touched by the guard branches under test. No
 * dedicated Integration/Browser spec of its own.
 *
 * `delete()`/`setInfo()`/`setCategory()` each cover their
 * CSRF-token-mismatch 403 guard, which fires before touching any real
 * service. This is a representative sample, not every one of the
 * remaining CSRF-gated methods in this file (also gated: `upload()`,
 * `uploadCompleted()`) -- the pattern is identical and already
 * well-established across this campaign's other Pwg* classes.
 *
 * Every other remaining method (`addChunk`/`addFile`/`add`/`addSimple`/
 * `upload`/`uploadAsync`/`uploadCompleted`) needs real upload/file/DB
 * state disproportionate for a guard-branch test and is not attempted
 * here.
 */
function pwgImagesTestSubject(): Images
{
    $ws = Kernel::container()->get(Images::class);
    if (! $ws instanceof Images) {
        throw new LogicException('Container returned an unexpected type for ' . Images::class);
    }

    return $ws;
}

/**
 * None of the branches under test here ever reach `$service->invoke()`
 * -- a bare, unregistered Server only needs to satisfy the method's
 * own type, same rationale as `PermissionsTest.php`'s own
 * pwgPermissionsTestServer() helper. Unlike most other Ws\Pwg* classes
 * in this campaign, Images' methods take `Server $service` by
 * value (not by reference), so the container-resolved instance can be
 * passed directly.
 */
function pwgImagesTestServer(): Server
{
    $server = Kernel::container()->get(Server::class);
    if (! $server instanceof Server) {
        throw new LogicException('Container returned an unexpected type for ' . Server::class);
    }

    return $server;
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

test('delete returns a 403 WsErrorResponse when the submitted pwg_token does not match the real CSRF token', function (): void {
    $ws = pwgImagesTestSubject();

    $result = $ws->delete([
        'image_id' => '1',
        'pwg_token' => 'wrong-token',
    ], pwgImagesTestServer());

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});

test('setInfo returns a 403 WsErrorResponse when a submitted pwg_token does not match the real CSRF token', function (): void {
    $ws = pwgImagesTestSubject();

    $result = $ws->setInfo([
        'image_id' => 1,
        'file' => null,
        'name' => null,
        'author' => null,
        'date_creation' => null,
        'comment' => null,
        'categories' => null,
        'tag_ids' => null,
        'level' => null,
        'single_value_mode' => 'fill_if_empty',
        'multiple_value_mode' => 'append',
        'pwg_token' => 'wrong-token',
    ], pwgImagesTestServer());

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});

test('setCategory returns a 403 WsErrorResponse when the submitted pwg_token does not match the real CSRF token', function (): void {
    $ws = pwgImagesTestSubject();

    $result = $ws->setCategory([
        'image_id' => [1],
        'category_id' => 1,
        'action' => 'associate',
        'pwg_token' => 'wrong-token',
    ], pwgImagesTestServer());

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});
