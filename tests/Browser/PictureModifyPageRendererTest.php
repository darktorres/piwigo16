<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use PgSql\Connection;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

function pictureModifyDbConnect(): mysqli|Connection
{
    return H::connect();
}

/**
 * Writes a real `plugin.json` + PSR-4-autoloadable `ExtensionInterface`
 * class -- the plugin/theme contract's own fixture shape, loaded via
 * `PluginConfig\PluginRegistry::bootActive()`. `$bootBodySource` is spliced verbatim into the
 * fixture class's own `boot()` method body -- the same "runs once, early
 * in the request" timing the old top-level `main.inc.php` code had.
 */
function pictureModifyWriteFixturePlugin(string $pluginDir, string $bootBodySource): void
{
    if (! is_dir($pluginDir . '/src')) {
        mkdir($pluginDir . '/src', 0o777, true);
    }

    $namespace = 'PiwigoTestFixture\\Ext' . bin2hex(random_bytes(6));

    file_put_contents($pluginDir . '/plugin.json', json_encode([
        'id' => basename($pluginDir),
        'name' => basename($pluginDir),
        'version' => '1.0.0',
        'description' => 'Test-only fixture plugin (tests/Browser/PictureModifyPageRendererTest.php).',
        'license' => 'MIT',
        'minPiwigo' => '16.3.0',
        'main' => $namespace . '\\Plugin',
        'autoload' => [
            'psr-4' => [
                $namespace . '\\' => 'src/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    file_put_contents($pluginDir . '/src/Plugin.php', <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Piwigo\\PluginConfig\\ExtensionContext;
        use Piwigo\\PluginConfig\\ExtensionInterface;

        final class Plugin implements ExtensionInterface
        {
            public function boot(ExtensionContext \$context): void
            {
                {$bootBodySource}
            }

            public function install(): void {}
            public function activate(): void {}
            public function deactivate(): void {}
            public function uninstall(): void {}
            public function update(string \$oldVersion, string \$newVersion): void {}

            public function subscribedEvents(): array
            {
                return [];
            }
        }

        PHP);
}

function pictureModifyRemoveFixturePlugin(string $pluginDir): void
{
    @unlink($pluginDir . '/src/Plugin.php');
    @rmdir($pluginDir . '/src');
    @unlink($pluginDir . '/plugin.json');
    if (is_dir($pluginDir)) {
        rmdir($pluginDir);
    }
}

/**
 * @return array{name: ?string, author: ?string, comment: ?string, level: int, date_creation: ?string}|null
 */
function pictureModifyImageRow(int $imageId): ?array
{
    $db = pictureModifyDbConnect();
    $row = H::dbFetchAssoc($db, sprintf('SELECT name, author, comment, level, date_creation FROM images WHERE id = %d', $imageId));
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
    $row = H::dbFetchAssoc($db, sprintf('SELECT COUNT(*) AS c FROM image_tag WHERE image_id = %d AND tag_id = %d', $imageId, $tagId));
    H::dbClose($db);

    return is_array($row) && (int) $row['c'] > 0;
}

function pictureModifyCategoryRepresentativeId(int $categoryId): ?int
{
    $db = pictureModifyDbConnect();
    $row = H::dbFetchAssoc($db, sprintf('SELECT representative_picture_id FROM categories WHERE id = %d', $categoryId));
    H::dbClose($db);

    if (! is_array($row) || $row['representative_picture_id'] === null) {
        return null;
    }

    return (int) $row['representative_picture_id'];
}

function pictureModifySetRotationCode(int $imageId, int $rotationCode): void
{
    $db = pictureModifyDbConnect();
    H::dbQuery($db, sprintf('UPDATE images SET rotation = %d WHERE id = %d', $rotationCode, $imageId));
    H::dbClose($db);
}

/**
 * Real filesystem sync, same site_update mechanism (and same real
 * repo-root `galleries/` directory) as Controller\Admin\
 * SiteUpdateSubControllerTest -- only a sync (never a tus upload, what
 * every other test in this file uses) ever writes a
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
    $row = H::dbFetchAssoc($db, sprintf("SELECT id FROM images WHERE file = '%s'", H::dbEscape($db, $file)));
    H::dbClose($db);

    return is_array($row) && isset($row['id']) ? (int) $row['id'] : null;
}

function pictureModifyCategoryIdByDir(int $siteId, string $dir): ?int
{
    $db = pictureModifyDbConnect();
    $row = H::dbFetchAssoc($db, sprintf("SELECT id FROM categories WHERE site_id = %d AND dir = '%s'", $siteId, H::dbEscape($db, $dir)));
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
 * bug), which is enough to assert
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

        return [
            'status' => $status,
            'body' => is_string($body) ? $body : '',
        ];
    };

    $baseUrl = H::baseUrl();
    $curl($baseUrl . '/identification.php');
    $curl($baseUrl . '/identification.php', [
        'username' => $username,
        'password' => $password,
        'login' => 'Login',
    ]);

    return [
        'curl' => $curl,
        'cookieJar' => $cookieJar,
        'baseUrl' => $baseUrl,
    ];
}

it('updates a photo\'s title/author/comment/level/date, sets a tag, and reports success', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Photo Modify Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Original Name');
    @unlink($image);

    expect(pictureModifyImageHasTag($imageId, 1))
        ->toBeFalse();

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
    // (singular).
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
    expect(pictureModifyImageHasTag($imageId, 1))
        ->toBeTrue();
});

it('single-escapes an HTML-special-character-bearing author/description, not double-escaped (P44-F)', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Photo Modify Escaping Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Original Name');
    @unlink($image);

    H::updateImageInfo($page, [
        'image_id' => $imageId,
        'author' => 'Author & "Quote"',
        'comment' => 'Description & "Quote"',
    ]);

    $page = H::navigateOk($page, '/admin.php?page=photo-' . $imageId);

    // #author is an <input value="...">, attribute position -- escapeAttr()
    // encodes both & and " (WebDriver's own .value getter decodes back to
    // the real characters). #description is a <textarea>...</textarea>,
    // element-text position -- escapeText() only encodes &, leaving the
    // quote literal, hence the plain-content assertion below instead.
    expect($page->value('input[name="author"]'))
        ->toBe('Author & "Quote"');
    $body = H::rawWebpage($page)->content();
    expect($body)
        ->toContain('Description &amp; "Quote"</textarea>');
    expect($body)
        ->not->toContain('Description &amp;amp;');
});

it('rejects a photo-modify submission with a missing CSRF token', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Photo Modify CSRF Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
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
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Photo Modify Represent Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
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
    expect(pictureModifyImageHasTag($imageId, 2))
        ->toBeTrue();

    $db = pictureModifyDbConnect();
    $assoc = H::dbFetchAssoc($db, sprintf('SELECT representative_picture_id FROM categories WHERE id = %d', $albumId));
    H::dbClose($db);
    expect(is_array($assoc) ? (int) $assoc['representative_picture_id'] : -1)->toBe($imageId);
});

it('synchronizes metadata from file via the sync_metadata CSRF-gated action', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Photo Modify Sync Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Photo Modify Sync Photo');
    @unlink($image);

    // sync_metadata (like delete) is read from $_GET, not $_POST,
    // per PictureModifyRequest::fromArrays().
    $token = H::pwgToken($page);
    $page = H::navigateOk($page, '/admin.php?page=photo&image_id=' . $imageId . '&sync_metadata=1&pwg_token=' . $token);

    $page->assertSee('Metadata synchronized from file');
});

it('deletes the photo via the CSRF-gated delete action and redirects to the gallery root', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Photo Modify Delete Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Photo Modify Delete Photo');
    @unlink($image);

    $token = H::pwgToken($page);
    // redirect() is a real Location header -- opaque under fetch(manual),
    // status always 0 (see this suite's own established convention for
    // this exact Fetch API caveat).
    $result = H::rawGet($page, '/admin.php?page=photo&image_id=' . $imageId . '&delete=1&pwg_token=' . $token);

    expect($result['status'])->toBe(0);
    expect(pictureModifyImageRow($imageId))
        ->toBeNull();
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

    $statusBody = H::curlApi($session['cookieJar'], 'GET', '/api/v1/session');
    $statusData = json_decode($statusBody, true);
    $pwgTokenRaw = is_array($statusData) ? ($statusData['pwgToken'] ?? null) : null;
    $pwgToken = is_string($pwgTokenRaw) ? $pwgTokenRaw : '';
    expect($pwgToken)
        ->not->toBe('');

    $albumBody = H::curlApi($session['cookieJar'], 'POST', '/api/v1/categories', [
        'name' => 'PM Delete Context Album ' . uniqid(),
    ], $pwgToken);
    $albumData = json_decode($albumBody, true);
    $albumIdRaw = is_array($albumData) ? ($albumData['id'] ?? null) : null;
    $albumId = is_numeric($albumIdRaw) ? (int) $albumIdRaw : 0;
    expect($albumId)
        ->toBeGreaterThan(0);

    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'PM Delete Context Photo');
    @unlink($image);

    $view = $curl($baseUrl . '/picture.php?/' . $imageId . '/category/' . $albumId);
    expect($view['status'])->toBe(200);

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

    expect($status)
        ->toBe(302);
    // The default (no-context) redirect target is a bare gallery-root
    // index URL with no album segment at all -- 'category/{albumId}' can
    // only appear here via this renderer's own str_replace() against the
    // session-recorded custom_context, proving the context branch (not
    // the plain makeIndexUrl() fallback one line below it) is what fired.
    expect(is_string($location) ? $location : '')
        ->toContain('category/' . $albumId);
    expect(pictureModifyImageRow($imageId))
        ->toBeNull();
});

it('renders U_JUMPTO from the session edit context, ahead of the authorized-category fallback', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'PM Jumpto Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
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

it('proceeds with the original submission when a picture_modify_before_update plugin handler returns something other than a PictureModifyBeforeUpdate instance', function (): void {
    // dispatch() never reads a handler's return value (Plan 2 Stage A
    // step 2 deleted the old instanceof-on-return-value enforcement), so
    // a misbehaving handler that returns garbage without touching
    // $event->data leaves $event->data exactly as the caller built it --
    // the request succeeds using the original, pre-hook submission,
    // rather than either crashing or silently degrading it.
    // PluginConfig\PluginRegistry::bootActive() boots every active
    // plugin's ExtensionInterface class on every request, the same live
    // mechanism a genuine misbehaving 3rd-party plugin would use -- same
    // fixture-plugin technique as PictureControllerTest's own "bogus
    // comment action" test. Content-marker-gated so it's a no-op for
    // every other concurrent request against this shared dev server
    // while active.
    $pluginId = 'pwgtest-picture-modify-bogus-hook';
    $pluginDir = dirname(__DIR__, 2) . '/plugins/' . $pluginId;
    $marker = 'PWGTEST_BOGUS_HOOK_MARKER_' . uniqid();

    pictureModifyWriteFixturePlugin($pluginDir, <<<PHP
        \\Piwigo\\Tests\\Support\\EventDispatcherTestFactory::get()->addTypedHandler(
            \\Piwigo\\Admin\\Event\\PictureModifyBeforeUpdate::class,
            static function (\\Piwigo\\Admin\\Event\\PictureModifyBeforeUpdate \$event): mixed {
                if (is_string(\$event->data['comment'] ?? null) && str_contains(\$event->data['comment'], '{$marker}')) {
                    return null;
                }

                return \$event;
            }
        );
        PHP);

    $pluginDb = pictureModifyDbConnect();
    H::dbQuery($pluginDb, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0.0')", $pluginId));
    H::dbClose($pluginDb);

    try {
        $page = H::asAdmin($this);
        $album = H::createCategory($page, [
            'name' => 'PM Bogus Hook Album ' . uniqid(),
        ]);
        if (! is_numeric($album['id'] ?? null)) {
            throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $album['id'];
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

        expect($result['status'])->toBe(200);

        // Proves the request reached updateFields() using $event->data
        // untouched by the misbehaving handler -- the real submission's
        // own name/comment, not silently dropped or replaced.
        $row = pictureModifyImageRow($imageId);
        expect($row['name'] ?? null)->toBe('Bogus Hook Title');
        expect($row['comment'] ?? null)->toBe('Bogus hook comment ' . $marker);
    } finally {
        $cleanupDb = pictureModifyDbConnect();
        H::dbQuery($cleanupDb, sprintf("DELETE FROM plugins WHERE id = '%s'", $pluginId));
        H::dbClose($cleanupDb);
        pictureModifyRemoveFixturePlugin($pluginDir);
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
    $page = H::asAdmin($this);

    $albumB = H::createCategory($page, [
        'name' => 'PM New Thumb Album B ' . uniqid(),
    ]);
    if (! is_numeric($albumB['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($albumB, true));
    }
    $albumBId = (int) $albumB['id'];
    $imageX = H::makeTestImage(uniqid());
    $imageXId = H::uploadPhotoViaApi($imageX, $albumBId, 'PM New Thumb Photo X');
    @unlink($imageX);
    expect(pictureModifyCategoryRepresentativeId($albumBId))
        ->toBe($imageXId);

    $albumC = H::createCategory($page, [
        'name' => 'PM New Thumb Album C ' . uniqid(),
    ]);
    if (! is_numeric($albumC['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($albumC, true));
    }
    $albumCId = (int) $albumC['id'];
    $imageY = H::makeTestImage(uniqid());
    $imageYId = H::uploadPhotoViaApi($imageY, $albumCId, 'PM New Thumb Photo Y (edit target)');
    @unlink($imageY);
    expect(pictureModifyCategoryRepresentativeId($albumCId))
        ->toBe($imageYId);

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
    expect(pictureModifyCategoryRepresentativeId($albumBId))
        ->toBe($imageYId);
});

it('swaps width/height and flips the FORMAT flag for a photo with a stored 90/270-degree rotation', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'PM Rotation Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    // H::makeTestImage() draws a fixed 200x150 (landscape) canvas, with no
    // EXIF orientation tag -- images.rotation stays 0 (no auto-rotation)
    // through a normal API upload. Written directly instead of via a real
    // EXIF Orientation tag: Admin\Image\ImageBackend::getRotationAngle()
    // needs a real embedded EXIF segment GD's own imagejpeg() can't
    // write, and ImageDerivativeControllerTest's own
    // idcSetImageRotationCode() already established this exact "UPDATE
    // images SET rotation directly" shortcut as this codebase's own
    // convention for exercising a stored (not live-EXIF-derived) rotation.
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'PM Rotation Photo');
    @unlink($image);

    // Rotation code 1 == 90 degrees (Admin\Image\ImageBackend::
    // getRotationCodeFromAngle()) -- in [1, 3], the swap branch under
    // test; code 2 (180 degrees) deliberately does NOT swap width/height,
    // so only 1 or 3 exercise this line.
    pictureModifySetRotationCode($imageId, 1);

    $result = H::rawGet($page, '/admin.php?page=photo&image_id=' . $imageId);

    expect($result['status'])->toBe(200);
    // $isWide is computed from $row['width']/$row['height'] AFTER this
    // renderer's own swap, as `$row['width'] >= $row['height']`. This
    // assertion is what pins the swap: unrotated, the image is 200x150
    // and wide, so only a real width/height swap (150x200) makes it
    // tall.
    //
    // The port kept piwigo16's own inverted "0:horizontal, 1:vertical"
    // comment verbatim and this test recorded that it was stale; P58
    // fixed it along with the two CSS class names, which said the
    // opposite of what their rules did (`.is-portrait` set width:100%).
    // A tall image is `.is-portrait` now, and that is what this asserts.
    expect($result['body'])->toContain('other-image-format is-portrait');
});

it('resolves storage_category_id from a filesystem-synced photo, marks it unlinkable-storage, and lists its multi-format sizes', function (): void {
    $dir = 'pm_sync_' . uniqid();
    $file = $dir . '.jpg';
    $tempDir = pictureModifyMakeTempDir($dir);
    mkdir($tempDir . '/pwg_format', 0777, true);

    $image = H::makeTestImage('PM Sync Photo');
    copy($image, $tempDir . '/' . $file);
    @unlink($image);
    // Site\LocalSiteReader::getFormats() floors raw bytes to KB
    // (floor($bytes / 1024)) before storing -- a real 1 MiB sibling file
    // stores a clean 1024 KB, and this renderer's own format-listing loop
    // re-divides that by 1024 to display "MB" (KB -> MB is correct here,
    // not a double-conversion bug -- confirmed against
    // SiteUpdateSubControllerTest's own suImageFormatFilesize() assertion,
    // which expects a raw 2048-byte fixture to store as literal KB `2`),
    // landing on an exact "1.00MB" instead of a rounding-fuzzy value.
    file_put_contents($tempDir . '/pwg_format/' . $dir . '.cr2', str_repeat('A', 1024 * 1024));

    $page = H::asAdmin($this);
    $token = H::pwgToken($page);
    $snapshot = H::snapshotConfig(['enable_formats']);

    try {
        H::setConfigValue('enable_formats', 'true');

        $created = pictureModifySync($page, $token);
        expect($created['status'])->toBe(200);
        expect($created['body'])->toContain('1 photos added in the database');

        $imageId = pictureModifyImageIdByFile($file);
        expect($imageId)
            ->not->toBeNull();
        assert($imageId !== null);
        $categoryId = pictureModifyCategoryIdByDir(1, $dir);
        expect($categoryId)
            ->not->toBeNull();

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

/**
 * autosize.ts's ready() callback, converted off jQuery in P49-A. The
 * autogrow() call inside it is an in-tree micro plugin and stays until
 * P49-B group 1; what this pins is the line beside it, which writes an
 * inline `overflow-y: hidden` onto every textarea and is the callback's
 * only directly observable effect.
 *
 * Whether a naive DOMContentLoaded port of ready() breaks is
 * timing-dependent and this page does not reliably show it -- checked, and
 * it still passed with the listener swapped in. LanguagesNewPageRendererTest
 * is where that failure reproduces. What this pins is narrower and
 * deterministic: the inline style the converted line writes.
 */
it('applies autosize\'s inline overflow-y to every textarea on load', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=photo-1&tab=properties');

    $overflows = $page->script(
        'Array.from(document.querySelectorAll("textarea")).map((t) => t.style.overflowY)'
    );

    expect($overflows)
        ->not->toBe([]);
    foreach ((array) $overflows as $value) {
        expect($value)->toBe('hidden');
    }
    $page->assertNoJavaScriptErrors();
});
