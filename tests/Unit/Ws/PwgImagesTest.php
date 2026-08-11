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
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgImages;
use Piwigo\Ws\Server;

/**
 * Piwigo\Ws\PwgImages -- the `pwg.images.*` WS methods (26 public
 * methods, the largest class in the B4 legacy WS API bucket). Resolved
 * via `Kernel::container()->get()` (same rationale as
 * `UpdatesSubControllerTest.php`) -- 25 constructor deps (incl.
 * `UploadService`, itself 14 deps), none touched by the guard branches
 * under test. No dedicated Integration/Browser spec of its own.
 *
 * `getInfo()` covers its own "image_id not found" 404 guard via a real
 * DB read against a deliberately non-existent `image_id`, same
 * B2-pattern approach as this campaign's Repository/Service tests.
 * `delete()`/`setInfo()`/`formatsDelete()`/`deleteOrphans()`/
 * `setCategory()` each cover their CSRF-token-mismatch 403 guard,
 * which fires before touching any real service. This is a
 * representative sample, not every one of the 9 CSRF-gated methods in
 * this file (also gated: `upload()`, `uploadCompleted()`,
 * `setMd5sum()`, `syncMetadata()`) -- the pattern is identical and
 * already well-established across this campaign's other Pwg* classes.
 *
 * Every other method (`addComment`/`rate`/`search`/`filteredSearchCreate`/
 * `setPrivacyLevel`/`setRank`/`addChunk`/`addFile`/`add`/`addSimple`/
 * `upload`/`uploadAsync`/`exist`/`formatsSearchImage`/`checkFiles`/
 * `checkUpload`/`emptyLounge`/`uploadCompleted`/`setMd5sum`/
 * `syncMetadata`) needs real upload/file/DB state disproportionate for
 * a guard-branch test and is not attempted here.
 */
function pwgImagesTestSubject(): PwgImages
{
    $ws = Kernel::container()->get(PwgImages::class);
    if (! $ws instanceof PwgImages) {
        throw new LogicException('Container returned an unexpected type for ' . PwgImages::class);
    }

    return $ws;
}

/**
 * None of the branches under test here ever reach `$service->invoke()`
 * -- a bare, unregistered Server only needs to satisfy the method's
 * own type, same rationale as `PwgPermissionsTest.php`'s own
 * pwgPermissionsTestServer() helper. Unlike most other Ws\Pwg* classes
 * in this campaign, PwgImages' methods take `Server $service` by
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

test('getInfo returns a 404 PwgError for an image_id with no real match', function (): void {
    $ws = pwgImagesTestSubject();

    $result = $ws->getInfo([
        'image_id' => 999999,
        'comments_page' => 0,
        'comments_per_page' => 10,
    ], pwgImagesTestServer());

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(404)
            ->and($result->message())
            ->toBe('image_id not found');
    }
});

test('delete returns a 403 PwgError when the submitted pwg_token does not match the real CSRF token', function (): void {
    $ws = pwgImagesTestSubject();

    $result = $ws->delete([
        'image_id' => '1',
        'pwg_token' => 'wrong-token',
    ], pwgImagesTestServer());

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(403);
    }
});

test('setInfo returns a 403 PwgError when a submitted pwg_token does not match the real CSRF token', function (): void {
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
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(403);
    }
});

test('formatsDelete returns a 403 PwgError when the submitted pwg_token does not match the real CSRF token', function (): void {
    $ws = pwgImagesTestSubject();

    $result = $ws->formatsDelete([
        'format_id' => 1,
        'pwg_token' => 'wrong-token',
    ], pwgImagesTestServer());

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(403);
    }
});

test('deleteOrphans returns a 403 PwgError when the submitted pwg_token does not match the real CSRF token', function (): void {
    $ws = pwgImagesTestSubject();

    $result = $ws->deleteOrphans([
        'block_size' => 1000,
        'pwg_token' => 'wrong-token',
    ], pwgImagesTestServer());

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(403);
    }
});

test('setCategory returns a 403 PwgError when the submitted pwg_token does not match the real CSRF token', function (): void {
    $ws = pwgImagesTestSubject();

    $result = $ws->setCategory([
        'image_id' => [1],
        'category_id' => 1,
        'action' => 'associate',
        'pwg_token' => 'wrong-token',
    ], pwgImagesTestServer());

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(403);
    }
});
