<?php

declare(strict_types=1);

use PgSql\Connection;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\PictureModifyPageRenderer (admin.php?page=photo, the
 * per-photo "Properties" tab) -- AdminExtendedSmokeTest.php's own
 * data-driven smoke sweep already visits this tab with a plain GET, so
 * this file focuses on the actual metadata-update submission this
 * renderer's own request-handling branch (~150 uncovered lines) never
 * gets exercised by that bare visit: title/author/comment/level/
 * date_creation, tag assignment, and moving the photo into another album.
 *
 * Coverage-gap closure batch also added: the delete/U_JUMPTO branches
 * that key off a session `edit_context` (UserService::getEditContext()),
 * a real filesystem-synced photo (the only way `storage_category_id`/
 * multi-format rows are ever non-null -- every OTHER test in this file
 * uploads via pwg.images.addSimple, which always leaves both null), a
 * directly-stored 90/270-degree rotation code (swap-width/height
 * branch), the new-album-representative branch (`$new_thumbnail_for`,
 * distinguished from the already-representative-at-upload-time case the
 * pre-existing "sets a plain (non-array) tag name..." test below only
 * looks like it covers), and a real fixture-plugin `main.inc.php` proving
 * that a PictureModifyBeforeUpdate handler returning something other than
 * a PictureModifyBeforeUpdate instance now fails the request loud
 * (dispatchChange()'s own instanceof enforcement) instead of silently
 * falling back to the pre-hook submission.
 *
 * Deliberately NOT covered, both non-behavioral:
 *  - `if (! isset($page['image']))`'s own FALSE branch: `$page` is a
 *    fresh local scratch array (`$page = [];`, this method's own
 *    top-of-body reset -- see that assignment's own docblock) reset on
 *    every single call, so `isset($page['image'])` is structurally
 *    guaranteed false every time this line runs; there is no real
 *    request shape (this renderer's only entry point) that could ever
 *    reach it already set. A pure static-analysis narrowing artifact
 *    left over from the legacy script's own `global $page` shape (where
 *    an earlier include COULD have already populated it), not a
 *    reachable branch in this ported, per-call-scoped form.
 *  - `$added_by = 'N/A';`: dead even in the original legacy source
 *    (admin/picture_modify.php, confirmed by direct read) -- assigned
 *    once and never read again there either (only `$row['added_by']`,
 *    a completely separate variable, is used by the `$intro_vars` build
 *    below it). Ported faithfully rather than silently dropped, but has
 *    zero observable effect on any output, so no behavioral test can
 *    target it.
 */

function pictureModifyDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

function pictureModifyDbConnect(): mysqli|Connection
{
    return H::connect();
}

/** @return array{name: ?string, author: ?string, comment: ?string, level: int, date_creation: ?string}|null */
function pictureModifyImageRow(int $imageId): ?array
{
    $db = pictureModifyDbConnect();
    $row = H::dbFetchAssoc($db, sprintf(
        'SELECT name, author, comment, level, date_creation FROM %simages WHERE id = %d',
        pictureModifyDbPrefix(),
        $imageId
    ));
    H::dbClose($db);

    if (! is_array($row)) {
        return null;
    }

    $name = $row['name'];
    $author = $row['author'];
    $comment = $row['comment'];
    $dateCreation = $row['date_creation'];

    return [
        'name' => is_string($name) ? $name : null,
        'author' => is_string($author) ? $author : null,
        'comment' => is_string($comment) ? $comment : null,
        'level' => (int) $row['level'],
        'date_creation' => is_string($dateCreation) ? $dateCreation : null,
    ];
}

function pictureModifyImageHasTag(int $imageId, int $tagId): bool
{
    $db = pictureModifyDbConnect();
    $row = H::dbFetchAssoc($db, sprintf(
        'SELECT COUNT(*) AS c FROM %simage_tag WHERE image_id = %d AND tag_id = %d',
        pictureModifyDbPrefix(),
        $imageId,
        $tagId
    ));
    H::dbClose($db);

    return is_array($row) && (int) $row['c'] > 0;
}

function pictureModifyCategoryRepresentativeId(int $categoryId): ?int
{
    $db = pictureModifyDbConnect();
    $row = H::dbFetchAssoc($db, sprintf(
        'SELECT representative_picture_id FROM %scategories WHERE id = %d',
        pictureModifyDbPrefix(),
        $categoryId
    ));
    H::dbClose($db);

    if (! is_array($row) || $row['representative_picture_id'] === null) {
        return null;
    }

    return (int) $row['representative_picture_id'];
}

function pictureModifySetRotationCode(int $imageId, int $rotationCode): void
{
    $db = pictureModifyDbConnect();
    H::dbQuery($db, sprintf(
        'UPDATE %simages SET rotation = %d WHERE id = %d',
        pictureModifyDbPrefix(),
        $rotationCode,
        $imageId
    ));
    H::dbClose($db);
}

/**
 * Real filesystem sync, same site_update mechanism (and same real
 * repo-root `galleries/` directory) as Controller\Admin\
 * SiteUpdateSubControllerTest -- only a sync (never a pwg.images.addSimple
 * WS upload, what every other test in this file uses) ever writes a
 * non-null `storage_category_id` or an `image_format` row, per
 * Controller\Admin\SiteUpdateSubController's own `$insert[
 * 'storage_category_id']`/`$insert_formats` and Image\ImageRepository's
 * own `IF(storage_category_id IS NULL, 'api', 'sync')` add-method probe.
 */
function pictureModifyGalleriesRoot(): string
{
    return dirname(__DIR__, 2) . '/galleries/';
}

function pictureModifyMakeTempDir(string $name): string
{
    $dir = pictureModifyGalleriesRoot() . $name;
    mkdir($dir, 0777, true);

    return $dir;
}

function pictureModifyRemoveDirRecursive(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $entries = scandir($dir);
    if ($entries !== false) {
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                pictureModifyRemoveDirRecursive($path);
            } else {
                @unlink($path);
            }
        }
    }

    @rmdir($dir);
}

/**
 * @param  array<string, string>  $overrides
 * @return array{status: int, body: string}
 */
function pictureModifySync(Webpage|PendingAwaitablePage|AwaitableWebpage $page, string $token, array $overrides = []): array
{
    return H::adminPost($page, '/admin.php?page=site_update&site=1', array_merge([
        'submit' => '1',
        'pwg_token' => $token,
        'sync' => 'files',
        'subcats-included' => '1',
        'privacy_level' => '0',
        'display_info' => '1',
        'add_to_caddie' => '0',
        'simulate' => '0',
    ], $overrides));
}

function pictureModifyImageIdByFile(string $file): ?int
{
    $db = pictureModifyDbConnect();
    $row = H::dbFetchAssoc($db, sprintf(
        "SELECT id FROM %simages WHERE file = '%s'",
        pictureModifyDbPrefix(),
        H::dbEscape($db, $file)
    ));
    H::dbClose($db);

    return is_array($row) && isset($row['id']) ? (int) $row['id'] : null;
}

function pictureModifyCategoryIdByDir(int $siteId, string $dir): ?int
{
    $db = pictureModifyDbConnect();
    $row = H::dbFetchAssoc($db, sprintf(
        "SELECT id FROM %scategories WHERE site_id = %d AND dir = '%s'",
        pictureModifyDbPrefix(),
        $siteId,
        H::dbEscape($db, $dir)
    ));
    H::dbClose($db);

    return is_array($row) && isset($row['id']) ? (int) $row['id'] : null;
}

/**
 * Raw curl through a persistent cookie jar, following redirects, using
 * the given credentials to log in first -- same shape as
 * PictureControllerTest's own `pictureCurlLoginSession()`, duplicated
 * (not imported) per this suite's own per-file-local-helpers convention.
 * Needed here (instead of H::loginAsAdmin()'s Playwright page + H::
 * rawGet()) only for the one test below that must read the real
 * `Location` response header of a redirect: H::rawGet()'s in-page
 * `fetch(..., {redirect: 'manual'})` reports every redirect as an opaque
 * status 0 with no header access (a Fetch API spec limitation, not a
 * bug -- confirmed elsewhere in this suite), which is enough to assert
 * "a redirect happened" but not "redirected to THIS specific URL".
 *
 * @return array{curl: Closure(string, array<string, string>=, bool=): array{status: int, body: string}, cookieJar: non-empty-string, baseUrl: string}
 */
function pictureModifyCurlLoginSession(string $username, string $password): array
{
    $cookieJar = tempnam(sys_get_temp_dir(), 'pwg_browser_session_');
    if ($cookieJar === false) {
        throw new RuntimeException('tempnam failed');
    }

    /** @param array<string, string> $fields */
    $curl = static function (string $url, array $fields = [], bool $followRedirects = true) use ($cookieJar): array {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $followRedirects);
        if ($fields !== []) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);

        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    };

    $baseUrl = H::baseUrl();
    $curl($baseUrl . '/identification.php');
    $curl($baseUrl . '/identification.php', [
        'username' => $username,
        'password' => $password,
        'login' => 'Login',
    ]);

    return ['curl' => $curl, 'cookieJar' => $cookieJar, 'baseUrl' => $baseUrl];
}

it('updates a photo\'s title/author/comment/level/date, sets a tag, and reports success', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Photo Modify Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Original Name');
    @unlink($image);

    expect(pictureModifyImageHasTag($imageId, 1))->toBeFalse();

    $result = H::adminPost($page, '/admin.php?page=photo&image_id=' . $imageId, [
        'pwg_token' => H::pwgToken($page),
        'submit' => '1',
        'name' => 'Updated Photo Title',
        'author' => 'Updated Author',
        'comment' => 'Updated description',
        'level' => '2',
        'date_creation' => '2026-01-15',
        // Fixture tag 1 ('nature') -- see this suite's own fixture-shape
        // memory notes. TagService::getTagIds() treats a plain string as
        // a tag NAME to look up/create; the '~~ID~~' sentinel is what
        // selects an existing tag by id directly (the real admin
        // tag-selector widget's own format for an already-picked tag).
        'tags' => ['~~1~~'],
        'associate' => [(string) $albumId],
    ]);

    expect($result['status'])->toBe(200);
    // The en_UK PO translation for this msgid ("Photo informations
    // updated", grammatically off) reads "Photo information updated"
    // (singular) -- confirmed against language/en_UK/admin.po directly
    // rather than assuming the source string's own wording.
    expect($result['body'])->toContain('Photo information updated');

    $row = pictureModifyImageRow($imageId);
    if ($row === null) {
        throw new RuntimeException("expected a real image row for id {$imageId}");
    }
    expect($row['name'])->toBe('Updated Photo Title');
    expect($row['author'])->toBe('Updated Author');
    expect($row['comment'])->toBe('Updated description');
    expect($row['level'])->toBe(2);
    expect($row['date_creation'])->toBe('2026-01-15 00:00:00');
    expect(pictureModifyImageHasTag($imageId, 1))->toBeTrue();
});

it('rejects a photo-modify submission with a missing CSRF token', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Photo Modify CSRF Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'CSRF Test Photo');
    @unlink($image);

    $result = H::adminPost($page, '/admin.php?page=photo&image_id=' . $imageId, [
        'submit' => '1',
        'name' => 'Should Not Be Applied',
    ]);

    expect($result['status'])->toBe(400);
    $row = pictureModifyImageRow($imageId);
    expect($row['name'] ?? null)->not->toBe('Should Not Be Applied');
});

it('sets a plain (non-array) tag name and assigns the photo as its new album representative', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Photo Modify Represent Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Photo Modify Represent Photo');
    @unlink($image);

    // '~~2~~' selects existing fixture tag 2 by id, sent as a bare string
    // (not wrapped in an array) -- the sibling shape to
    // BatchManagerUnitPageRendererTest's own equivalent scalar-tag test.
    $result = H::adminPost($page, '/admin.php?page=photo&image_id=' . $imageId, [
        'pwg_token' => H::pwgToken($page),
        'submit' => '1',
        'level' => '0',
        'tags' => '~~2~~',
        'represent' => [(string) $albumId],
    ]);

    expect($result['status'])->toBe(200);
    expect(pictureModifyImageHasTag($imageId, 2))->toBeTrue();

    $db = pictureModifyDbConnect();
    $prefix = pictureModifyDbPrefix();
    $assoc = H::dbFetchAssoc($db, sprintf('SELECT representative_picture_id FROM %scategories WHERE id = %d', $prefix, $albumId));
    H::dbClose($db);
    expect(is_array($assoc) ? (int) $assoc['representative_picture_id'] : -1)->toBe($imageId);
});

it('synchronizes metadata from file via the sync_metadata CSRF-gated action', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Photo Modify Sync Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Photo Modify Sync Photo');
    @unlink($image);

    // sync_metadata (like delete) is read from $_GET, not $_POST --
    // confirmed by direct read of PictureModifyRequest::fromArrays().
    $token = H::pwgToken($page);
    $page = H::navigateOk($page, '/admin.php?page=photo&image_id=' . $imageId . '&sync_metadata=1&pwg_token=' . $token);

    $page->assertSee('Metadata synchronized from file');
});

it('deletes the photo via the CSRF-gated delete action and redirects to the gallery root', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Photo Modify Delete Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Photo Modify Delete Photo');
    @unlink($image);

    $token = H::pwgToken($page);
    // redirect() is a real Location header -- opaque under fetch(manual),
    // status always 0 (see this suite's own established convention for
    // this exact Fetch API caveat).
    $result = H::rawGet($page, '/admin.php?page=photo&image_id=' . $imageId . '&delete=1&pwg_token=' . $token);

    expect($result['status'])->toBe(0);
    expect(pictureModifyImageRow($imageId))->toBeNull();
});

it('honors the session edit context as the delete redirect target instead of the default gallery root', function (): void {
    // UserService::saveEditContext() (called unconditionally by
    // PictureController::__invoke() for every admin viewer) records the
    // exact section a photo was viewed from in $_SESSION['edit_context'],
    // keyed by image id -- this renderer's own delete branch prefers that
    // recorded context over its own bare gallery-root fallback whenever
    // one is present. Needs a single persistent cookie-jar session across
    // both the picture.php view (which writes the context) and the
    // delete itself (which reads it back), so this uses
    // pictureModifyCurlLoginSession() rather than H::loginAsAdmin()'s
    // Playwright page.
    $session = pictureModifyCurlLoginSession(H::ADMIN_USER, H::ADMIN_PASS);
    $curl = $session['curl'];
    $baseUrl = $session['baseUrl'];

    $albumResponse = $curl($baseUrl . '/ws.php?format=json', ['method' => 'pwg.categories.add', 'name' => 'PM Delete Context Album ' . uniqid()]);
    $albumData = json_decode($albumResponse['body'], true);
    $albumResult = is_array($albumData) ? ($albumData['result'] ?? null) : null;
    $albumIdRaw = is_array($albumResult) ? ($albumResult['id'] ?? null) : null;
    $albumId = is_numeric($albumIdRaw) ? (int) $albumIdRaw : 0;
    expect($albumId)->toBeGreaterThan(0);

    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'PM Delete Context Photo');
    @unlink($image);

    $view = $curl($baseUrl . '/picture.php?/' . $imageId . '/category/' . $albumId);
    expect($view['status'])->toBe(200);

    $statusResponse = $curl($baseUrl . '/ws.php?format=json', ['method' => 'pwg.session.getStatus']);
    $statusData = json_decode($statusResponse['body'], true);
    $statusResult = is_array($statusData) ? ($statusData['result'] ?? null) : null;
    $pwgTokenRaw = is_array($statusResult) ? ($statusResult['pwg_token'] ?? null) : null;
    $pwgToken = is_string($pwgTokenRaw) ? $pwgTokenRaw : '';
    expect($pwgToken)->not->toBe('');

    // Raw curl, redirects NOT followed, capturing the real Location header
    // via CURLINFO_REDIRECT_URL -- see pictureModifyCurlLoginSession()'s
    // own docblock for why H::rawGet() can't prove this.
    $ch = curl_init($baseUrl . '/admin.php?page=photo&image_id=' . $imageId . '&delete=1&pwg_token=' . $pwgToken);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $session['cookieJar']);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $session['cookieJar']);
    curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    unset($ch);
    @unlink($session['cookieJar']);

    expect($status)->toBe(302);
    // The default (no-context) redirect target is a bare gallery-root
    // index URL with no album segment at all -- 'category/{albumId}' can
    // only appear here via this renderer's own str_replace() against the
    // session-recorded custom_context, proving the context branch (not
    // the plain makeIndexUrl() fallback one line below it) is what fired.
    expect(is_string($location) ? $location : '')->toContain('category/' . $albumId);
    expect(pictureModifyImageRow($imageId))->toBeNull();
});

it('renders U_JUMPTO from the session edit context, ahead of the authorized-category fallback', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'PM Jumpto Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'PM Jumpto Photo');
    @unlink($image);

    // Viewing the photo through picture.php first, in the SAME admin
    // browser session, records this exact section as the image's edit
    // context (UserService::saveEditContext()) -- the real mechanism
    // this renderer's own U_JUMPTO branch below depends on, same
    // context-recording step as the delete test above.
    $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId);

    $result = H::rawGet($page, '/admin.php?page=photo&image_id=' . $imageId);

    expect($result['status'])->toBe(200);
    // makePictureUrl(['image_id' => $image_id]) (no category param) makes
    // a bare "picture.php?/{id}" -- 'category/{albumId}' can only be
    // appended onto it via the recorded custom_context, proving this is
    // the context branch's own href, not the authorized-category-ids
    // elseif fallback (which has no way to know about this exact album).
    expect($result['body'])->toContain('picture.php?/' . $imageId . '/category/' . $albumId);
});

it('fatal-errors instead of silently falling back when a picture_modify_before_update plugin handler returns something other than a PictureModifyBeforeUpdate instance', function (): void {
    // dispatchChange() now enforces its own instanceof contract -- a
    // misbehaving handler makes the request fail loud (an HTTP 500)
    // rather than silently falling back to the pre-hook submission.
    // Admin\PluginLoader::loadPlugins() include_once()s every active
    // plugins/{id}/main.inc.php on every request, the same live mechanism
    // a genuine misbehaving 3rd-party plugin would use -- same
    // fixture-plugin technique as PictureControllerTest's own "bogus
    // comment action" test. Content-marker-gated so it's a no-op for
    // every other concurrent request against this shared dev server
    // while active.
    $pluginId = 'pwgtest-picture-modify-bogus-hook';
    $pluginDir = dirname(__DIR__, 2) . '/plugins/' . $pluginId;
    $marker = 'PWGTEST_BOGUS_HOOK_MARKER_' . uniqid();

    if (! is_dir($pluginDir) && ! mkdir($pluginDir, 0o777, true) && ! is_dir($pluginDir)) {
        throw new RuntimeException('failed to create plugin dir: ' . $pluginDir);
    }
    $mainFile = $pluginDir . '/main.inc.php';
    file_put_contents($mainFile, <<<PHP
        <?php

        declare(strict_types=1);

        /*
        Plugin Name: PictureModifyPageRenderer Test -- Bogus before_update Hook
        Version: 1.0.0
        Description: Test-only fixture plugin (tests/Browser/PictureModifyPageRendererTest.php).
        */

        \\Piwigo\\Core\\Kernel::container()->get(\\Piwigo\\PluginConfig\\EventDispatcher::class)->addTypedHandler(
            \\Piwigo\\Event\\Picture\\PictureModifyBeforeUpdate::class,
            static function (\\Piwigo\\Event\\Picture\\PictureModifyBeforeUpdate \$event): mixed {
                if (is_string(\$event->data['comment'] ?? null) && str_contains(\$event->data['comment'], '{$marker}')) {
                    return null;
                }

                return \$event;
            }
        );

        PHP);

    $pluginDb = pictureModifyDbConnect();
    H::dbQuery($pluginDb, sprintf(
        "INSERT INTO %splugins (id, state, version) VALUES ('%s', 'active', '1.0.0')",
        pictureModifyDbPrefix(),
        $pluginId
    ));
    H::dbClose($pluginDb);

    try {
        $page = H::loginAsAdmin($this);
        $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'PM Bogus Hook Album ' . uniqid()]);
        $albumResult = $album['result'] ?? null;
        if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
            throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $albumResult['id'];
        $image = H::makeTestImage(uniqid());
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'PM Bogus Hook Photo');
        @unlink($image);

        $result = H::adminPost($page, '/admin.php?page=photo&image_id=' . $imageId, [
            'pwg_token' => H::pwgToken($page),
            'submit' => '1',
            'name' => 'Bogus Hook Title',
            'comment' => 'Bogus hook comment ' . $marker,
            'level' => '0',
        ]);

        // display_errors is off site-wide (Core\ErrorCollector::install()
        // forces it, and php.ini already has it off too), so the response
        // body itself carries no exception detail to assert on -- the
        // status code is the only reliable, environment-independent
        // signal.
        expect($result['status'])->toBe(500);

        // Proves the whole request failed before ever reaching
        // updateFields() -- the photo keeps its original pre-submission
        // name/comment, not the bogus edit.
        $row = pictureModifyImageRow($imageId);
        expect($row['name'] ?? null)->toBe('PM Bogus Hook Photo');
        expect($row['comment'] ?? null)->not->toBe('Bogus hook comment ' . $marker);
    } finally {
        $cleanupDb = pictureModifyDbConnect();
        H::dbQuery($cleanupDb, sprintf("DELETE FROM %splugins WHERE id = '%s'", pictureModifyDbPrefix(), $pluginId));
        H::dbClose($cleanupDb);
        @unlink($mainFile);
        @rmdir($pluginDir);
    }
});

it('sets a newly-represented album as representative even when it already has a different representative', function (): void {
    // CategoryService::updateCategory() -- called from within both
    // ImageService::associateImagesToCategories() (the 'associate' field
    // below) and the upload path itself -- only ever auto-assigns a
    // representative to a category that has elements but NO representative
    // yet (allow_random_representative defaults false in this fixture, see
    // Config\CurrentConfig::$allowRandomRepresentative's own default). The
    // pre-existing "sets a plain (non-array) tag name..." test above
    // submits 'represent' for the SAME album the photo was uploaded into,
    // which that auto-assign already made representative at upload time
    // -- its own assertion passes whether or not this renderer's own
    // $new_thumbnail_for/setRepresentativeImageForCategories() branch
    // ever runs. This test uses two albums, each already representative
    // of a DIFFERENT photo before the submission, so only this renderer's
    // own explicit call can change either one.
    $page = H::loginAsAdmin($this);

    $albumB = H::wsCall($page, 'pwg.categories.add', ['name' => 'PM New Thumb Album B ' . uniqid()]);
    $albumBResult = $albumB['result'] ?? null;
    if (! is_array($albumBResult) || ! is_numeric($albumBResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($albumB, true));
    }
    $albumBId = (int) $albumBResult['id'];
    $imageX = H::makeTestImage(uniqid());
    $imageXId = H::uploadPhotoViaApi($imageX, $albumBId, 'PM New Thumb Photo X');
    @unlink($imageX);
    expect(pictureModifyCategoryRepresentativeId($albumBId))->toBe($imageXId);

    $albumC = H::wsCall($page, 'pwg.categories.add', ['name' => 'PM New Thumb Album C ' . uniqid()]);
    $albumCResult = $albumC['result'] ?? null;
    if (! is_array($albumCResult) || ! is_numeric($albumCResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($albumC, true));
    }
    $albumCId = (int) $albumCResult['id'];
    $imageY = H::makeTestImage(uniqid());
    $imageYId = H::uploadPhotoViaApi($imageY, $albumCId, 'PM New Thumb Photo Y (edit target)');
    @unlink($imageY);
    expect(pictureModifyCategoryRepresentativeId($albumCId))->toBe($imageYId);

    // Edit photo Y: associate it into B too (alongside its existing album
    // C), but only represent B -- $represented_albums (read BEFORE this
    // submission, at this renderer's own top-of-method line) is [C] for
    // Y, so $new_thumbnail_for = array_diff([B], [C]) = [B], a real,
    // non-empty new-representative set.
    $result = H::adminPost($page, '/admin.php?page=photo&image_id=' . $imageYId, [
        'pwg_token' => H::pwgToken($page),
        'submit' => '1',
        'level' => '0',
        'associate' => [(string) $albumCId, (string) $albumBId],
        'represent' => [(string) $albumBId],
    ]);

    expect($result['status'])->toBe(200);
    expect(pictureModifyCategoryRepresentativeId($albumBId))->toBe($imageYId);
});

it('swaps width/height and flips the FORMAT flag for a photo with a stored 90/270-degree rotation', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'PM Rotation Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    // H::makeTestImage() draws a fixed 200x150 (landscape) canvas, with no
    // EXIF orientation tag -- images.rotation stays 0 (no auto-rotation)
    // through a normal API upload. Written directly instead of via a real
    // EXIF Orientation tag: Admin\Image\PwgImage::get_rotation_angle()
    // needs a real embedded EXIF segment GD's own imagejpeg() can't
    // write, and ImageDerivativeControllerTest's own
    // idcSetImageRotationCode() already established this exact "UPDATE
    // images SET rotation directly" shortcut as this codebase's own
    // convention for exercising a stored (not live-EXIF-derived) rotation.
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'PM Rotation Photo');
    @unlink($image);

    // Rotation code 1 == 90 degrees (Admin\Image\PwgImage::
    // get_rotation_code_from_angle()) -- in [1, 3], the swap branch under
    // test; code 2 (180 degrees) deliberately does NOT swap width/height,
    // so only 1 or 3 exercise this line.
    pictureModifySetRotationCode($imageId, 1);

    $result = H::rawGet($page, '/admin.php?page=photo&image_id=' . $imageId);

    expect($result['status'])->toBe(200);
    // FORMAT is computed from $row['width']/$row['height'] AFTER this
    // renderer's own swap, as `($row['width'] >= $row['height']) ? 1 : 0`
    // (a faithful, byte-for-byte port of piwigo16's own
    // admin/picture_modify.php -- its own comment, "0:horizontal,
    // 1:vertical", is itself stale/inverted relative to what the
    // expression actually computes; kept verbatim, not "fixed", per this
    // codebase's own no-unrelated-changes-during-a-port precedent).
    // picture_modify.tpl renders a different inline style for each value
    // on the two preview <img> tags: {if $FORMAT}width:100%;
    // max-height:100%;{else}max-width:100%; height:100%;{/if}. Unrotated,
    // this 200x150 (width >= height) image is FORMAT=1 -- only a real
    // width/height swap (150x200, width < height) flips it to FORMAT=0
    // ("max-width:100%; height:100%;"), confirmed live.
    expect($result['body'])->toContain('max-width:100%; height:100%;');
});

it('resolves storage_category_id from a filesystem-synced photo, marks it unlinkable-storage, and lists its multi-format sizes', function (): void {
    $dir = 'pm_sync_' . uniqid();
    $file = $dir . '.jpg';
    $tempDir = pictureModifyMakeTempDir($dir);
    mkdir($tempDir . '/pwg_format', 0777, true);

    $image = H::makeTestImage('PM Sync Photo');
    copy($image, $tempDir . '/' . $file);
    @unlink($image);
    // Site\LocalSiteReader::get_formats() floors raw bytes to KB
    // (floor($bytes / 1024)) before storing -- a real 1 MiB sibling file
    // stores a clean 1024 KB, and this renderer's own format-listing loop
    // re-divides that by 1024 to display "MB" (KB -> MB is correct here,
    // not a double-conversion bug -- confirmed against
    // SiteUpdateSubControllerTest's own suImageFormatFilesize() assertion,
    // which expects a raw 2048-byte fixture to store as literal KB `2`),
    // landing on an exact "1.00MB" instead of a rounding-fuzzy value.
    file_put_contents($tempDir . '/pwg_format/' . $dir . '.cr2', str_repeat('A', 1024 * 1024));

    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);
    $snapshot = H::snapshotConfig(['enable_formats']);

    try {
        H::setConfigValue('enable_formats', 'true');

        $created = pictureModifySync($page, $token);
        expect($created['status'])->toBe(200);
        expect($created['body'])->toContain('1 photos added in the database');

        $imageId = pictureModifyImageIdByFile($file);
        expect($imageId)->not->toBeNull();
        assert($imageId !== null);
        $categoryId = pictureModifyCategoryIdByDir(1, $dir);
        expect($categoryId)->not->toBeNull();

        $result = H::rawGet($page, '/admin.php?page=photo&image_id=' . $imageId);
        expect($result['status'])->toBe(200);

        // This photo is linked to exactly one album: the one the sync
        // just created for it, which is also its own storage_category_id
        // -- so $row_category_id === $storage_category_id is true for
        // that (only) related-categories loop iteration, rendering the
        // "can't dissociate, this is the storage album" icon
        // (icon-help-circled) instead of the normal removable-link one
        // (icon-cancel-circled remove-item), which never appears here.
        expect($result['body'])->toContain('icon-help-circled help-item tiptip');
        expect($result['body'])->not->toContain('icon-cancel-circled remove-item');

        expect($result['body'])->toContain('Formats: cr2 (1.00MB)');
    } finally {
        pictureModifyRemoveDirRecursive($tempDir);
        H::restoreConfig($snapshot);
        // Resync once more so the now-deleted temp directory/photo are
        // detected as removed and cleared from the database, matching
        // SiteUpdateSubControllerTest's own finally-block cleanup shape.
        H::adminPost($page, '/admin.php?page=site_update&site=1', [
            'submit' => '1',
            'pwg_token' => $token,
            'sync' => 'files',
            'subcats-included' => '1',
            'privacy_level' => '0',
            'simulate' => '0',
        ]);
    }
});
