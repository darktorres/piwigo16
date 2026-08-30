<?php

declare(strict_types=1);

use PgSql\Connection;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\PictureController (picture.php) -- the single-photo
 * detail page. Covers the hit-counter increment (and its "don't
 * double-count a same-picture reload" branch), the favorites toggle (a
 * real add_to_favorites/remove_from_favorites action round trip verified
 * against favorites), the invalid-image_id 404 branch, and the
 * admin comment-moderation actions this controller's own switch handles
 * (delete_comment/validate_comment) -- distinct from CommentsController's
 * own moderation actions (comments.php), covered separately.
 *
 * Every real navigation below uses the pretty-URL form
 * `picture.php?/{imageId}/category/{albumId}`, not a bare
 * `picture.php?image_id={imageId}`, since the bare form makes
 * SectionPopulator default to the flat "categories" section with no
 * specific category selected -- whether a freshly uploaded photo's id
 * lands inside that default (unfiltered, sorted, paginated) items list is
 * NOT deterministic (depends on how many other images already exist
 * site-wide, their sort order, and the default page size), so a bare
 * image_id can 404 or hit an unrelated accessDenied() branch depending on
 * ambient DB state -- exactly the kind of ambient-state fragility this
 * suite otherwise avoids. Scoping every request to the real album makes
 * PictureController's own `$page_category !== null` branch deterministic.
 *
 * Deliberately NOT covered: __invoke()'s own `if (! $section_context
 * instanceof SectionContext) { throw new \RuntimeException(...); }` guard,
 * right after `SectionPopulator::populate()`. That method's own real
 * implementation unconditionally calls `SectionContextRegistry::set()` as
 * the very last thing it does -- there is no real HTTP
 * request shape that reaches this line with a null registry short of
 * swapping in a fake SectionPopulator, which would be testing a mock of
 * this controller's own collaborator, not real behavior. A pure
 * defensive type-narrowing guard against the property's nullable type,
 * not a reachable branch.
 */
function pictureDbConnect(): mysqli|Connection
{
    return H::connect();
}

/**
 * Writes a real `plugin.json` + PSR-4-autoloadable `ExtensionInterface`
 * class -- the plugin/theme contract's own fixture shape, loaded via
 * `PluginConfig\PluginRegistry::bootActive()`. `$bootBodySource` is spliced verbatim into the
 * fixture class's own `boot()` method body -- the same "runs once, early
 * in the request" timing the old top-level `main.inc.php` code had.
 * The namespace is derived from random bytes, not `$pluginId` (which can
 * start with a digit -- not a legal leading character for a PHP
 * identifier).
 */
function pictureWriteFixturePlugin(string $pluginDir, string $bootBodySource): void
{
    if (! is_dir($pluginDir . '/src')) {
        mkdir($pluginDir . '/src', 0o777, true);
    }

    $namespace = 'PiwigoTestFixture\\Ext' . bin2hex(random_bytes(6));

    file_put_contents($pluginDir . '/plugin.json', json_encode([
        'id' => basename($pluginDir),
        'name' => basename($pluginDir),
        'version' => '1.0.0',
        'description' => 'Test-only fixture plugin (tests/Browser/PictureControllerTest.php).',
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

function pictureRemoveFixturePlugin(string $pluginDir): void
{
    @unlink($pluginDir . '/src/Plugin.php');
    @rmdir($pluginDir . '/src');
    @unlink($pluginDir . '/plugin.json');
    @rmdir($pluginDir);
}

function pictureHitCount(int $imageId): int
{
    $db = pictureDbConnect();
    $row = H::dbFetchAssoc($db, sprintf('SELECT hit FROM images WHERE id = %d', $imageId));
    H::dbClose($db);

    return is_array($row) && isset($row['hit']) ? (int) $row['hit'] : -1;
}

function pictureFavoriteExists(int $imageId, int $userId): bool
{
    $db = pictureDbConnect();
    $row = H::dbFetchAssoc($db, sprintf('SELECT COUNT(*) AS c FROM favorites WHERE image_id = %d AND user_id = %d', $imageId, $userId));
    H::dbClose($db);

    return is_array($row) && (int) $row['c'] > 0;
}

/** Inserts a real comment row directly (matches RegenerateFixtureTest's own direct-insert shape) and returns its id. */
/**
 * comments.validated is a genuine boolean column on Postgres -- a bare
 * 0/1 literal is valid MySQL tinyint(1) input but Postgres rejects it
 * outright ("column is of type boolean but expression is of type
 * integer"). Matches RegenerateFixtureTest's own
 * $sqlTrue/$sqlFalse convention for the identical column.
 */
function pictureInsertComment(int $imageId, string $author, string $content, bool $validated, ?int $authorId = null): int
{
    $db = pictureDbConnect();
    $sqlTrue = $db instanceof mysqli ? '1' : 'true';
    $sqlFalse = $db instanceof mysqli ? '0' : 'false';
    H::dbQuery($db, sprintf("INSERT INTO comments (image_id, date, author, anonymous_id, author_id, content, validated, validation_date) VALUES (%d, NOW(), '%s', '127.0.0.9', %s, '%s', %s, %s)", $imageId, H::dbEscape($db, $author), $authorId === null ? 'NULL' : (string) $authorId, H::dbEscape($db, $content), $validated ? $sqlTrue : $sqlFalse, $validated ? 'NOW()' : 'NULL'));
    $id = H::dbInsertId($db);
    H::dbClose($db);

    return $id;
}

/**
 * @return array{validated: int}|null
 */
function pictureCommentRow(int $commentId): ?array
{
    $db = pictureDbConnect();
    $row = H::dbFetchAssoc($db, sprintf('SELECT validated FROM comments WHERE id = %d', $commentId));
    H::dbClose($db);

    // pg_fetch_assoc() represents a boolean column as 't'/'f' -- a naive
    // (int) cast silently mishandles both.
    return is_array($row) ? [
        'validated' => H::dbToBool($row['validated']) ? 1 : 0,
    ] : null;
}

function pictureCategoryRepresentativeId(int $categoryId): ?int
{
    $db = pictureDbConnect();
    $row = H::dbFetchAssoc($db, sprintf('SELECT representative_picture_id FROM categories WHERE id = %d', $categoryId));
    H::dbClose($db);

    return is_array($row) && $row['representative_picture_id'] !== null ? (int) $row['representative_picture_id'] : null;
}

function pictureCaddieExists(int $imageId, int $userId): bool
{
    $db = pictureDbConnect();
    $row = H::dbFetchAssoc($db, sprintf('SELECT COUNT(*) AS c FROM caddie WHERE element_id = %d AND user_id = %d', $imageId, $userId));
    H::dbClose($db);

    return is_array($row) && (int) $row['c'] > 0;
}

function pictureRateValue(int $imageId, int $userId): ?int
{
    $db = pictureDbConnect();
    // RateService::rate() always stores the real (IP-derived) anonymous_id
    // column value, even for a logged-in user -- it's not a literal ''
    // sentinel for "not anonymous" -- so this only filters by
    // element_id/user_id (deleteExistingRate() already guarantees at most
    // one row per user_id+element_id for a non-anonymous rater).
    $row = H::dbFetchAssoc($db, sprintf('SELECT rate FROM rate WHERE element_id = %d AND user_id = %d', $imageId, $userId));
    H::dbClose($db);

    return is_array($row) ? (int) $row['rate'] : null;
}

/**
 * Plain GET through a persistent cookie jar -- used to keep a guest
 * session's `image_order` choice alive across two separate requests
 * (GalleryController stores it server-side in the session, not just in
 * the URL).
 *
 * @param  non-empty-string  $cookieJar
 */
function pictureGetWithCookies(string $cookieJar, string $path): string
{
    $ch = curl_init(H::baseUrl() . '/' . ltrim($path, '/'));
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $body = curl_exec($ch);

    return is_string($body) ? $body : '';
}

it('increments the hit counter on first view, then not on an immediate same-picture reload', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Picture Test Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    // Piwigo\Admin\Upload\UploadService::addUploadedFile() de-duplicates by
    // md5sum whenever CurrentConfig::uploadDetectDuplicate() is enabled
    // (this fixture's own config ships
    // upload_detect_duplicate=true), so a fixed image label -- H::
    // makeTestImage() draws it straight onto the pixel data -- produces
    // byte-IDENTICAL file content across repeated runs against this
    // suite's own persistent (not-reimported-between-runs) dev DB,
    // returning a PRE-EXISTING image id (already hit>0 from an earlier
    // run) instead of creating a fresh one.
    //
    // A uniqid()-suffixed label alone does NOT fix this: GD's
    // imagestring() draws left-to-right from x=30 on a 200px-wide canvas
    // and silently clips anything past the right edge, and 'Hit Count
    // Photo ' (16 chars) at font 5's 9px/char already consumes ~154 of
    // the ~170 visible px -- leaving only the uniqid() suffix's first ~2
    // hex digits actually rendered, which are themselves the slow-moving,
    // effectively-constant leading digits of the current Unix timestamp
    // (uniqid()'s own encoding), so two different-but-similarly-prefixed
    // labels can render to byte-identical JPEGs. The PIXEL label (must
    // stay short enough to render in full) and the DB `name` field (the
    // descriptive, human-readable text picture.latte actually displays,
    // asserted below) are deliberately decoupled here for exactly this
    // reason.
    $pixelLabel = uniqid();
    $displayName = 'Hit Count Photo';
    $image = H::makeTestImage($pixelLabel);
    $imageId = H::uploadPhotoViaApi($image, $albumId, $displayName);
    @unlink($image);

    expect(pictureHitCount($imageId))
        ->toBe(0);

    $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId);
    $page->assertSee($displayName);
    expect(pictureHitCount($imageId))
        ->toBe(1);

    // SessionService's own 'referer_image_id' session var: an immediate
    // reload of the SAME picture (same session) must not double-count.
    $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId);
    expect(pictureHitCount($imageId))
        ->toBe(1);
});

it('adds and removes a photo from favorites via the picture.php action links, verified in favorites', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Picture Test Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    // Genuinely-unique pixel label, decoupled from the descriptive DB name
    // -- see the hit-counter test above's own comment for why a longer,
    // prefixed label defeats itself (GD clips it before the differentiating
    // suffix renders, so the md5 collides with an earlier run's image
    // anyway). Real risk here too: a collision would return a pre-existing
    // image possibly already favorited if an earlier run crashed mid-toggle.
    $pixelLabel = uniqid();
    $displayName = 'Favorite Photo';
    $image = H::makeTestImage($pixelLabel);
    $imageId = H::uploadPhotoViaApi($image, $albumId, $displayName);
    @unlink($image);

    expect(pictureFavoriteExists($imageId, 1))
        ->toBeFalse();

    $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId . '&action=add_to_favorites');
    expect(pictureFavoriteExists($imageId, 1))
        ->toBeTrue();

    $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId . '&action=remove_from_favorites');
    expect(pictureFavoriteExists($imageId, 1))
        ->toBeFalse();
});

it('404s with "Page not found" for a nonexistent image_id', function (): void {
    expect(H::httpStatus('picture.php?image_id=999999999'))->toBe(404);
    expect(H::httpBody('picture.php?image_id=999999999'))->toContain('Page not found');
});

it('lets an admin delete and validate comments directly from picture.php\'s own actions', function (): void {
    $page = H::asAdmin($this);
    $pwgToken = H::pwgToken($page);

    $album = H::createCategory($page, [
        'name' => 'Picture Test Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage('Comment Moderation Photo');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Comment Moderation Photo');
    @unlink($image);

    // author_id=3 (regular_user, a real registered account); the
    // anonymous (NULL author_id) case is covered separately below.
    $toDeleteId = pictureInsertComment($imageId, 'delete-me-' . uniqid(), 'This one gets deleted.', true, 3);
    $toValidateId = pictureInsertComment($imageId, 'validate-me-' . uniqid(), 'This one gets validated.', false, 3);

    $beforeRow = pictureCommentRow($toValidateId);
    if ($beforeRow === null) {
        throw new RuntimeException('expected a real comment row for id ' . $toValidateId);
    }
    expect($beforeRow['validated'])->toBe(0);

    $page = H::navigateOk(
        $page,
        '/picture.php?/' . $imageId . '/category/' . $albumId . '&action=delete_comment&comment_to_delete=' . $toDeleteId . '&pwg_token=' . $pwgToken
    );
    expect(pictureCommentRow($toDeleteId))
        ->toBeNull();

    $page = H::navigateOk(
        $page,
        '/picture.php?/' . $imageId . '/category/' . $albumId . '&action=validate_comment&comment_to_validate=' . $toValidateId . '&pwg_token=' . $pwgToken
    );
    $row = pictureCommentRow($toValidateId);
    if ($row === null) {
        throw new RuntimeException('expected a real comment row for id ' . $toValidateId);
    }
    expect($row['validated'])->toBe(1);
});

it('delete_comment succeeds for an anonymous (NULL author_id) comment', function (): void {
    // Regression test for a fixed bug: CommentService::getCommentAuthorId()
    // used to collapse "comment not found" and "comment has NULL author_id"
    // (a real, common state for any guest/anonymous-authored comment) down
    // to the same `false` sentinel. That `false` then flowed into
    // AccessControl::canManageComment(string $action, int|string
    // $commentAuthorId), whose 2nd parameter's declared type did NOT
    // include bool, and under this project's `declare(strict_types=1)`
    // triggers a real, uncaught TypeError. getCommentAuthorId() now
    // returns `null` for this case (see
    // CommentRepository::findAuthorId()'s 3-state contract) and
    // canManageComment() now accepts `int|string|null`, treating a null
    // author as "no owner to compare against" (admins can still manage it,
    // as verified here; a non-admin never can).
    $cookieJar = tempnam(sys_get_temp_dir(), 'pwg_browser_bug_');
    if ($cookieJar === false) {
        throw new RuntimeException('tempnam failed');
    }

    $post = static function (string $url, array $fields = []) use ($cookieJar): array {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
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
    $post($baseUrl . '/identification.php');
    $post($baseUrl . '/identification.php', [
        'username' => H::ADMIN_USER,
        'password' => H::ADMIN_PASS,
        'login' => 'Login',
    ]);

    $statusBody = H::curlApi($cookieJar, 'GET', '/api/v1/session');
    $decodedStatus = json_decode($statusBody, true);
    $pwgTokenRaw = is_array($decodedStatus) ? ($decodedStatus['pwgToken'] ?? null) : null;
    $pwgToken = is_string($pwgTokenRaw) || is_int($pwgTokenRaw) ? (string) $pwgTokenRaw : '';
    expect($pwgToken)
        ->not->toBe('');

    $albumBody = H::curlApi($cookieJar, 'POST', '/api/v1/categories', [
        'name' => 'Picture Bug Test Album ' . uniqid(),
    ], $pwgToken);
    $decodedAlbum = json_decode($albumBody, true);
    $albumIdRaw = is_array($decodedAlbum) ? ($decodedAlbum['id'] ?? null) : null;
    $albumId = is_numeric($albumIdRaw) ? (int) $albumIdRaw : 0;
    expect($albumId)
        ->toBeGreaterThan(0);

    $image = H::makeTestImage('Anon Comment Bug Photo');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Anon Comment Bug Photo');
    @unlink($image);

    // authorId: null -> a real anonymous/guest comment, matching what any
    // real, un-registered visitor leaves.
    $anonCommentId = pictureInsertComment($imageId, 'guest', 'An anonymous visitor left this.', true, null);

    $result = $post($baseUrl . '/picture.php?/' . $imageId . '/category/' . $albumId . '&action=delete_comment&comment_to_delete=' . $anonCommentId . '&pwg_token=' . $pwgToken);

    @unlink($cookieJar);

    expect($result['status'])->toBe(302);
    expect(pictureCommentRow($anonCommentId))
        ->toBeNull();
});

it("edits a comment's own content via the edit_comment action, validating it as admin", function (): void {
    // Distinct from delete_comment/validate_comment above: edit_comment
    // is the "change a comment's own text" flow
    // (CommentService::updateComment()), never exercised before. Its
    // ephemeral post key is only ever rendered into the page for the ONE
    // comment currently being edited (comment_list.latte's own
    // {if isset($comment.IN_EDIT)} guard around the hidden `key` field),
    // so a real 2-step interaction is required: a first GET with
    // action=edit_comment&comment_to_edit=<id> (no `content` posted, so
    // PictureController's own `if ($pictureRequest->content !== null...)`
    // guard skips straight to rendering the edit form) to obtain a real
    // key, then a real POST using it -- mirrors clicking "Edit" then
    // submitting the form, not a fabricated key.
    //
    // Raw curl throughout (not H::adminPost()'s in-browser fetch(manual)):
    // a successful edit takes the 'validate' branch, which issues a real
    // redirect() -- fetch(manual)'s own Response.status is always the
    // spec's opaque 0 for a redirect, never the real 302,
    // so FOLLOWLOCATION is required to observe
    // the real final status. Matches "delete_comment succeeds for an
    // anonymous comment"'s own raw-curl login pattern above.
    $cookieJar = tempnam(sys_get_temp_dir(), 'pwg_browser_editcomment_');
    if ($cookieJar === false) {
        throw new RuntimeException('tempnam failed');
    }

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
        'username' => H::ADMIN_USER,
        'password' => H::ADMIN_PASS,
        'login' => 'Login',
    ]);

    $statusBody = H::curlApi($cookieJar, 'GET', '/api/v1/session');
    $decodedStatus = json_decode($statusBody, true);
    $pwgTokenRaw = is_array($decodedStatus) ? ($decodedStatus['pwgToken'] ?? null) : null;
    $pwgToken = is_string($pwgTokenRaw) || is_int($pwgTokenRaw) ? (string) $pwgTokenRaw : '';
    expect($pwgToken)
        ->not->toBe('');

    $albumBody = H::curlApi($cookieJar, 'POST', '/api/v1/categories', [
        'name' => 'Picture Edit Comment Album ' . uniqid(),
    ], $pwgToken);
    $decodedAlbum = json_decode($albumBody, true);
    $albumIdRaw = is_array($decodedAlbum) ? ($decodedAlbum['id'] ?? null) : null;
    $albumId = is_numeric($albumIdRaw) ? (int) $albumIdRaw : 0;
    expect($albumId)
        ->toBeGreaterThan(0);

    $image = H::makeTestImage('Edit Comment Photo');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Edit Comment Photo');
    @unlink($image);

    $commentId = pictureInsertComment($imageId, 'edit-me-' . uniqid(), 'Original content.', false, 3);

    $editUrl = $baseUrl . '/picture.php?/' . $imageId . '/category/' . $albumId
        . '&action=edit_comment&comment_to_edit=' . $commentId;

    $getResult = $curl($editUrl);
    if (preg_match('/name="key" value="([^"]+)"/', $getResult['body'], $matches) !== 1) {
        throw new RuntimeException('Could not find the edit-comment form\'s hidden key field in: ' . $getResult['body']);
    }
    $key = html_entity_decode($matches[1]);

    // EphemeralKeyService::generate(2, ...) (PictureCommentRenderer's own
    // edit-mode key) requires >=2 real wall-clock seconds between issuing
    // and verifying the key -- a deliberate "the form couldn't have been
    // submitted this fast" anti-bot check: verify()
    // rejects (silently falls through to $commentAction = 'reject', a
    // 200 with the comment left unchanged, no error) when posted
    // immediately after the GET.
    sleep(3);

    $newContent = 'Edited content ' . uniqid();
    $postResult = $curl($editUrl, [
        'content' => $newContent,
        'website_url' => '',
        'key' => $key,
        'pwg_token' => $pwgToken,
    ]);

    @unlink($cookieJar);

    expect($postResult['status'])->toBe(200);
    expect($postResult['body'])->not->toContain('Fatal error');

    $db = pictureDbConnect();
    $row = H::fetchAssocOrFail($db, sprintf('SELECT content, validated FROM comments WHERE id = %d', $commentId));
    H::dbClose($db);
    expect($row['content'])->toBe($newContent);
    // Admin editing any comment always takes the 'validate' branch
    // (CommentService::updateComment(): `!commentsValidation() ||
    // isAdmin()`), regardless of the fixture's own comments_validation
    // setting.
    expect(H::dbToBool($row['validated']) ? 1 : 0)->toBe(1);
});

it('navigates between previous/next/first/last items across a 3-photo album, ordered by title', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Nav Test Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];

    $suffix = uniqid();
    $imageA = H::makeTestImage($suffix . 'a');
    $idA = H::uploadPhotoViaApi($imageA, $albumId, 'Nav Photo A ' . $suffix);
    @unlink($imageA);
    $imageB = H::makeTestImage($suffix . 'b');
    $idB = H::uploadPhotoViaApi($imageB, $albumId, 'Nav Photo B ' . $suffix);
    @unlink($imageB);
    $imageC = H::makeTestImage($suffix . 'c');
    $idC = H::uploadPhotoViaApi($imageC, $albumId, 'Nav Photo C ' . $suffix);
    @unlink($imageC);

    $cookieJar = tempnam(sys_get_temp_dir(), 'pwg_nav_');
    if ($cookieJar === false) {
        throw new RuntimeException('tempnam failed');
    }

    // image_order=1 is "Photo title, A -> Z" (CategoryService::
    // getPreferredImageOrders()) -- GalleryController stores the choice in
    // the session, so it also governs the $items ordering PictureController
    // computes for the very same album, giving deterministic prev/next/
    // first/last positions purely from the photo titles.
    pictureGetWithCookies($cookieJar, '/index.php?/category/' . $albumId . '&image_order=1');

    $middleBody = pictureGetWithCookies($cookieJar, '/picture.php?/' . $idB . '/category/' . $albumId);
    expect($middleBody)
        ->toContain('Previous :');
    expect($middleBody)
        ->toContain('Nav Photo A ' . $suffix);
    expect($middleBody)
        ->toContain('Next :');
    expect($middleBody)
        ->toContain('Nav Photo C ' . $suffix);

    $firstBody = pictureGetWithCookies($cookieJar, '/picture.php?/' . $idA . '/category/' . $albumId);
    expect($firstBody)
        ->not->toContain('Previous :');
    expect($firstBody)
        ->toContain('Next :');
    expect($firstBody)
        ->toContain('Nav Photo B ' . $suffix);

    $lastBody = pictureGetWithCookies($cookieJar, '/picture.php?/' . $idC . '/category/' . $albumId);
    expect($lastBody)
        ->toContain('Previous :');
    expect($lastBody)
        ->toContain('Nav Photo B ' . $suffix);
    expect($lastBody)
        ->not->toContain('Next :');

    @unlink($cookieJar);
});

it('sets a photo as the album representative via the set_as_representative action', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Representative Test Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    // A freshly created album auto-assigns its first-ever uploaded photo
    // as representative -- upload a second photo and
    // explicitly re-target it, so this test proves the action itself
    // changes the representative rather than observing an already-set
    // default.
    $firstImage = H::makeTestImage(uniqid());
    $firstImageId = H::uploadPhotoViaApi($firstImage, $albumId, 'First Photo');
    @unlink($firstImage);
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Representative Photo');
    @unlink($image);

    expect(pictureCategoryRepresentativeId($albumId))
        ->toBe($firstImageId);

    $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId . '&action=set_as_representative');
    expect(pictureCategoryRepresentativeId($albumId))
        ->toBe($imageId);
});

it('adds a photo to the caddie via the add_to_caddie action', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Caddie Test Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Caddie Photo');
    @unlink($image);

    expect(pictureCaddieExists($imageId, 1))
        ->toBeFalse();

    $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId . '&action=add_to_caddie');
    expect(pictureCaddieExists($imageId, 1))
        ->toBeTrue();
});

it('rates a photo via the rate action', function (): void {
    // RateService::rate() silently no-ops (returns false, never inserts a
    // row) unless CurrentConfig::rateEnabled() is true -- and this
    // fixture's own `rate` config param (not `rate_enabled`: the DB
    // param/property mapping is `'rate' => 'rateEnabled'`)
    // is explicitly seeded 'false', so rating is genuinely disabled by
    // default in this environment.
    $snapshot = H::snapshotConfig(['rate']);
    H::setConfigValue('rate', 'true');

    try {
        $page = H::asAdmin($this);
        $album = H::createCategory($page, [
            'name' => 'Rate Test Album ' . uniqid(),
        ]);
        if (! is_numeric($album['id'] ?? null)) {
            throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $album['id'];
        $image = H::makeTestImage(uniqid());
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'Rate Photo');
        @unlink($image);

        expect(pictureRateValue($imageId, 1))
            ->toBeNull();

        // The action's own success path ends in RedirectServiceInterface::
        // redirect() -- adminPost() uses fetch(..., {redirect:'manual'}), so a
        // real 30x comes back as an opaque status 0, not the real code.
        $result = H::adminPost($page, '/picture.php?/' . $imageId . '/category/' . $albumId . '&action=rate', [
            'rate' => '4',
        ]);
        expect($result['status'])->toBe(0);
        expect(pictureRateValue($imageId, 1))
            ->toBe(4);
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('rejects an edit_comment submission whose key is used before its 2-second minimum age', function (): void {
    $cookieJar = tempnam(sys_get_temp_dir(), 'pwg_browser_editcomment_reject_');
    if ($cookieJar === false) {
        throw new RuntimeException('tempnam failed');
    }

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
        'username' => H::ADMIN_USER,
        'password' => H::ADMIN_PASS,
        'login' => 'Login',
    ]);

    $statusBody = H::curlApi($cookieJar, 'GET', '/api/v1/session');
    $decodedStatus = json_decode($statusBody, true);
    $pwgTokenRaw = is_array($decodedStatus) ? ($decodedStatus['pwgToken'] ?? null) : null;
    $pwgToken = is_string($pwgTokenRaw) || is_int($pwgTokenRaw) ? (string) $pwgTokenRaw : '';
    expect($pwgToken)
        ->not->toBe('');

    $albumBody = H::curlApi($cookieJar, 'POST', '/api/v1/categories', [
        'name' => 'Picture Reject Comment Album ' . uniqid(),
    ], $pwgToken);
    $decodedAlbum = json_decode($albumBody, true);
    $albumIdRaw = is_array($decodedAlbum) ? ($decodedAlbum['id'] ?? null) : null;
    $albumId = is_numeric($albumIdRaw) ? (int) $albumIdRaw : 0;
    expect($albumId)
        ->toBeGreaterThan(0);

    $image = H::makeTestImage('Reject Comment Photo');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Reject Comment Photo');
    @unlink($image);

    $commentId = pictureInsertComment($imageId, 'reject-me-' . uniqid(), 'Original content.', false, 3);

    $editUrl = $baseUrl . '/picture.php?/' . $imageId . '/category/' . $albumId
        . '&action=edit_comment&comment_to_edit=' . $commentId;

    $getResult = $curl($editUrl);
    if (preg_match('/name="key" value="([^"]+)"/', $getResult['body'], $matches) !== 1) {
        throw new RuntimeException('Could not find the edit-comment form\'s hidden key field in: ' . $getResult['body']);
    }
    $key = html_entity_decode($matches[1]);

    // No sleep here, unlike the sibling "edits a comment's own content"
    // test above -- EphemeralKeyService::verify() requires >=2 real
    // wall-clock seconds since the key was issued, so posting immediately
    // deterministically hits CommentService::updateComment()'s own
    // $commentAction = 'reject' branch: a 200 response
    // (the switch's own 'reject' case never sets $perform_redirect), and
    // the comment's content column left untouched.
    $newContent = 'Rejected content ' . uniqid();
    $postResult = $curl($editUrl, [
        'content' => $newContent,
        'website_url' => '',
        'key' => $key,
        'pwg_token' => $pwgToken,
    ]);

    @unlink($cookieJar);

    expect($postResult['status'])->toBe(200);
    expect($postResult['body'])->not->toContain('Fatal error');
    // $_SESSION['page_errors'][] does have a real reader: HtmlService::
    // flushMessageMode() reads `$_SESSION['page_' . $mode]` generically
    // (not the literal string 'page_errors'), which a plain repo-wide grep
    // for that literal string misses -- the same
    // mechanism PasswordController's own fix (a873f5ca7d)
    // relies on for its own page_errors flash. Since 'reject' never
    // redirects, this same response (not a follow-up one) is what
    // flushPageMessages() renders it into.
    expect($postResult['body'])->toContain('Your comment has NOT been registered because it did not pass the validation rules');

    $db = pictureDbConnect();
    $row = H::fetchAssocOrFail($db, sprintf('SELECT content FROM comments WHERE id = %d', $commentId));
    H::dbClose($db);
    expect($row['content'])->toBe('Original content.');
});

it('toggles the show_metadata session flag on repeated ?metadata visits without erroring', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Picture Metadata Toggle Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Metadata Toggle Photo');
    @unlink($image);

    // First visit: isShowMetadataEnabled() is false -> sets it to 1.
    $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId . '&metadata');
    $page->assertNoJavaScriptErrors();
    // Second visit, same session: no longer null -> unsets it. Neither
    // branch has any template-visible effect of its own ($url_metadata
    // is a plain "current URL + metadata param" link, not
    // conditioned on the session var's value) -- exercising both real
    // branches without erroring is the correct assertion here.
    $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId . '&metadata');
    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'picture.php show_metadata toggle');

    // This test's own two visits just left $_SESSION['show_metadata']
    // toggled to an unpredictable state (set on the first visit, unset on
    // the second -- see the comment above) -- H::asAdmin()'s cached
    // session is shared across the whole suite run, so a later caller must
    // not inherit it. See BrowserTestHelpers::$sharedSessionKnownClean's
    // own docblock.
    H::markSharedSessionDirty();
});

it('renders a related tag link for a photo with a real assigned tag', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Picture Related Tags Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Related Tags Photo');
    @unlink($image);

    $tagName = 'Related Tag ' . uniqid();
    $tagResult = H::createTag($page, [
        'name' => $tagName,
    ]);
    $tagId = $tagResult['id'] ?? null;
    if (! is_numeric($tagId)) {
        throw new RuntimeException('createTag did not return a numeric id: ' . var_export($tagResult, true));
    }

    H::updateImageInfo($page, [
        'image_id' => (string) $imageId,
        'tag_ids' => (string) $tagId,
    ]);

    // picture.latte's own {if ($display_info['tags'] and isset($related_tags))}
    // gate needs the fixture's real picture_informations config to have
    // tags=true -- true by default in this fixture, not
    // overridden here.
    $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId);
    $page->assertSee($tagName);
    $page->assertNoJavaScriptErrors();
});

/**
 * Raw curl through a persistent cookie jar, following redirects, using the given credentials to log in first.
 *
 * @return array{curl: Closure(string, array<string, string>=, bool=): array{status: int, body: string}, cookieJar: non-empty-string, baseUrl: string}
 */
function pictureCurlLoginSession(string $username, string $password): array
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

function pictureSetImageDateAvailable(int $imageId, string $mysqlDateTime): void
{
    $db = pictureDbConnect();
    H::dbQuery($db, sprintf("UPDATE images SET date_available = '%s' WHERE id = %d", H::dbEscape($db, $mysqlDateTime), $imageId));
    H::dbClose($db);
}

/**
 * Directly links an image to an additional category -- for the multi-category breadcrumb test below, which needs a photo genuinely associated with 2+ real albums, not achievable through pwg.images.addSimple's own single-category upload.
 */
function pictureAddImageToCategory(int $imageId, int $categoryId): void
{
    $db = pictureDbConnect();
    H::dbQuery($db, sprintf('INSERT INTO image_category (image_id, category_id) VALUES (%d, %d)', $imageId, $categoryId));
    H::dbClose($db);
}

/**
 * Directly strips every image_category row for an image -- for the fetchOne()===false access-denied branch below, which needs a real, otherwise-normal image genuinely orphaned from every category (not reachable by simply viewing it through the wrong album, which the row_level/filtered checks intercept first).
 */
function pictureRemoveImageFromAllCategories(int $imageId): void
{
    $db = pictureDbConnect();
    H::dbQuery($db, sprintf('DELETE FROM image_category WHERE image_id = %d', $imageId));
    H::dbClose($db);
}

/**
 * Directly inserts an image_format row -- for the format-list building test below (download-URL fallback/Lang-key label lookup/filesize MB-formatting), not reachable through any real endpoint that lets a test control the stored filesize precisely.
 */
function pictureInsertImageFormat(int $imageId, string $ext, int $filesizeKb): void
{
    $db = pictureDbConnect();
    H::dbQuery($db, sprintf("INSERT INTO image_format (image_id, ext, filesize) VALUES (%d, '%s', %d)", $imageId, H::dbEscape($db, $ext), $filesizeKb));
    H::dbClose($db);
}

/**
 * Reads the `pwg_id` session cookie's raw value out of a curl cookie-jar
 * file (Netscape format) -- for pictureSessionDerivType() below, which
 * needs the exact id to look the session row up by.
 */
function pictureCookieJarSessionId(string $cookieJar): string
{
    $contents = file_get_contents($cookieJar);
    if ($contents === false) {
        throw new RuntimeException('failed to read cookie jar: ' . $cookieJar);
    }
    foreach (explode("\n", $contents) as $line) {
        $fields = explode("\t", $line);
        if (($fields[5] ?? null) === 'pwg_id' && isset($fields[6])) {
            return trim($fields[6]);
        }
    }

    throw new RuntimeException('pwg_id cookie not found in jar: ' . $cookieJar);
}

/**
 * Reads the real `pwg_picture_deriv` session var straight out of the
 * DB-backed `sessions` table (Piwigo\Session\SessionHandler's own save
 * handler), keyed the same way that handler stores it:
 * SessionService::remoteAddrHash() . $pwgIdCookieValue. The hash prefix
 * is IP-derived (the first 2 octets of an IPv4 REMOTE_ADDR, hex-encoded
 * -- see SessionService::remoteAddrHash()'s own docblock), and empty for
 * IPv6 or no-IP requests -- NOT a fixed value, and NOT safe to hardcode:
 * whether the test runner's `localhost` base URL resolves to an IPv4 or
 * IPv6 loopback address depends on this machine's own resolver
 * configuration (`getent hosts localhost` on this
 * environment returns `::1`, IPv6, giving an empty hash), which this
 * test has no business depending on. A suffix match on the raw cookie
 * value sidesteps the hash entirely -- PHP's session id is already a
 * long, cryptographically random string, so a `LIKE '%<id>'` match is
 * unambiguous regardless of what (if anything) is prepended to it.
 * Session data uses PHP's own default `session.serialize_handler` format,
 * and every real session var this app writes is itself namespaced with a
 * `pwg_` prefix (SessionService's own convention) -- a plain regex avoids
 * depending on session_decode()'s own ambient ini state from this
 * separate CLI process.
 */
function pictureSessionDerivType(string $pwgIdCookieValue): ?string
{
    $db = pictureDbConnect();
    $row = H::dbFetchAssoc($db, sprintf("SELECT data FROM sessions WHERE id LIKE '%%%s'", H::dbEscape($db, $pwgIdCookieValue)));
    H::dbClose($db);

    $dataRaw = is_array($row) ? ($row['data'] ?? null) : null;
    $data = is_string($dataRaw) ? $dataRaw : '';
    if (preg_match('/pwg_picture_deriv\|s:\d+:"([^"]*)";/', $data, $matches) === 1) {
        return $matches[1];
    }

    return null;
}

it("shows the access-denied page for a photo whose privacy level exceeds the viewer's, reached via a mismatched album", function (): void {
    // PictureController::__invoke()'s own row_level > user_level check
    // (L193-198) only runs inside the "this image_id doesn't belong to
    // the current section's item list" fallback -- so the request must
    // deliberately target the photo through an album it does NOT belong
    // to, not its real one.
    $adminSession = pictureCurlLoginSession(H::ADMIN_USER, H::ADMIN_PASS);
    $curl = $adminSession['curl'];
    $baseUrl = $adminSession['baseUrl'];

    $statusBody = H::curlApi($adminSession['cookieJar'], 'GET', '/api/v1/session');
    $decodedStatus = json_decode($statusBody, true);
    $pwgTokenRaw = is_array($decodedStatus) ? ($decodedStatus['pwgToken'] ?? null) : null;
    $pwgToken = is_string($pwgTokenRaw) || is_int($pwgTokenRaw) ? (string) $pwgTokenRaw : '';
    expect($pwgToken)
        ->not->toBe('');

    $ownAlbumBody = H::curlApi($adminSession['cookieJar'], 'POST', '/api/v1/categories', [
        'name' => 'Level Test Own Album ' . uniqid(),
    ], $pwgToken);
    $ownAlbumData = json_decode($ownAlbumBody, true);
    $ownAlbumIdRaw = is_array($ownAlbumData) ? ($ownAlbumData['id'] ?? null) : null;
    $ownAlbumId = is_numeric($ownAlbumIdRaw) ? (int) $ownAlbumIdRaw : 0;
    expect($ownAlbumId)
        ->toBeGreaterThan(0);

    $otherAlbumBody = H::curlApi($adminSession['cookieJar'], 'POST', '/api/v1/categories', [
        'name' => 'Level Test Other Album ' . uniqid(),
    ], $pwgToken);
    $otherAlbumData = json_decode($otherAlbumBody, true);
    $otherAlbumIdRaw = is_array($otherAlbumData) ? ($otherAlbumData['id'] ?? null) : null;
    $otherAlbumId = is_numeric($otherAlbumIdRaw) ? (int) $otherAlbumIdRaw : 0;
    expect($otherAlbumId)
        ->toBeGreaterThan(0);

    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $ownAlbumId, 'Level Test Photo');
    @unlink($image);

    // level=8 is above any non-admin user's default level.
    H::curlApi($adminSession['cookieJar'], 'POST', '/api/v1/images/actions/set-privacy-level', [
        'imageIds' => [$imageId],
        'level' => 8,
    ], $pwgToken);

    $regularSession = pictureCurlLoginSession('regular_user', 'regular_user_pass');
    $result = $regularSession['curl']($baseUrl . '/picture.php?/' . $imageId . '/category/' . $otherAlbumId);

    @unlink($adminSession['cookieJar']);
    @unlink($regularSession['cookieJar']);

    expect($result['status'])->toBe(401);
    expect($result['body'])->toContain('You are not authorized to access the requested page');
});

it('shows "requested image is filtered" for a backdated photo excluded by an active recent-content filter, reached via a mismatched album', function (): void {
    $adminSession = pictureCurlLoginSession(H::ADMIN_USER, H::ADMIN_PASS);
    $curl = $adminSession['curl'];
    $baseUrl = $adminSession['baseUrl'];

    $statusBody = H::curlApi($adminSession['cookieJar'], 'GET', '/api/v1/session');
    $decodedStatus = json_decode($statusBody, true);
    $pwgTokenRaw = is_array($decodedStatus) ? ($decodedStatus['pwgToken'] ?? null) : null;
    $pwgToken = is_string($pwgTokenRaw) || is_int($pwgTokenRaw) ? (string) $pwgTokenRaw : '';
    expect($pwgToken)
        ->not->toBe('');

    $ownAlbumBody = H::curlApi($adminSession['cookieJar'], 'POST', '/api/v1/categories', [
        'name' => 'Filtered Test Own Album ' . uniqid(),
    ], $pwgToken);
    $ownAlbumData = json_decode($ownAlbumBody, true);
    $ownAlbumIdRaw = is_array($ownAlbumData) ? ($ownAlbumData['id'] ?? null) : null;
    $ownAlbumId = is_numeric($ownAlbumIdRaw) ? (int) $ownAlbumIdRaw : 0;
    expect($ownAlbumId)
        ->toBeGreaterThan(0);

    $otherAlbumBody = H::curlApi($adminSession['cookieJar'], 'POST', '/api/v1/categories', [
        'name' => 'Filtered Test Other Album ' . uniqid(),
    ], $pwgToken);
    $otherAlbumData = json_decode($otherAlbumBody, true);
    $otherAlbumIdRaw = is_array($otherAlbumData) ? ($otherAlbumData['id'] ?? null) : null;
    $otherAlbumId = is_numeric($otherAlbumIdRaw) ? (int) $otherAlbumIdRaw : 0;
    expect($otherAlbumId)
        ->toBeGreaterThan(0);

    // A fresh photo keeps the filtered-in album non-empty for the "last
    // 1 day" window (so FilterState::visibleImages() is a real,
    // non-empty list, never the '-1' "computed nothing" sentinel
    // PictureController deliberately treats as "no restriction").
    $freshImage = H::makeTestImage(uniqid());
    H::uploadPhotoViaApi($freshImage, $ownAlbumId, 'Filter Recent Photo');
    @unlink($freshImage);

    $oldImage = H::makeTestImage(uniqid());
    $oldImageId = H::uploadPhotoViaApi($oldImage, $ownAlbumId, 'Filter Old Photo');
    @unlink($oldImage);
    // Dated 30 days before the app's OWN clock, not the machine's. The
    // recent-period window is SQL built by SqlDialect::
    // getRecentPeriodExpression(), which resolves CURRENT_DATE through
    // Env::now() -- i.e. PIWIGO_TEST_NOW -- precisely so fixture data dated
    // relative to the frozen instant does not drift out from under it. A
    // real strtotime('-30 days') here reintroduced exactly that drift from
    // the other side: it walks forward every day while the window stays
    // pinned, so the "old" photo eventually lands inside it and the page
    // stops being filtered. Found as a deterministic failure, not a flake.
    $frozenNow = getenv('PIWIGO_TEST_NOW');
    $frozenNow = $frozenNow !== false && $frozenNow !== '' ? $frozenNow : 'now';
    pictureSetImageDateAvailable($oldImageId, date('Y-m-d H:i:s', strtotime('-30 days', (int) strtotime($frozenNow))));

    // Activates FilterService::initializeFromRequest()'s session-persisted
    // recent-content filter (start-recent-1 -> a real 1-day window):
    // CurrentConfig::filterPages()'s 'default' entry has used=true, so
    // this runs on every subsequent
    // request in this same cookie-jar session, including picture.php.
    $curl($baseUrl . '/index.php?filter=start-recent-1');

    $result = $curl($baseUrl . '/picture.php?/' . $oldImageId . '/category/' . $otherAlbumId);

    @unlink($adminSession['cookieJar']);

    expect($result['status'])->toBe(404);
    expect($result['body'])->toContain('The requested image is filtered');
});

it('redirects to the section listing (not back to the picture) when removing a favorite from within the favorites section', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Favorites Up Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Favorites Up Photo');
    @unlink($image);

    H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId . '&action=add_to_favorites');
    expect(pictureFavoriteExists($imageId, 1))
        ->toBeTrue();

    // Raw curl, no auto-follow, capturing the real Location header directly
    // via CURLINFO_REDIRECT_URL: PictureController's remove_from_favorites
    // case redirects to $url_up (the section listing) instead of
    // $url_self (this same picture) specifically when the CURRENT section
    // being viewed is 'favorites' -- distinct from the plain add/remove
    // round trip above, which views the photo via its real album and so
    // takes the $url_self branch instead.
    $session = pictureCurlLoginSession(H::ADMIN_USER, H::ADMIN_PASS);
    $ch = curl_init($session['baseUrl'] . '/picture.php?/' . $imageId . '/favorites&action=remove_from_favorites');
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
    expect(is_string($location) ? $location : '')
        ->not->toContain('picture.php');
    expect(pictureFavoriteExists($imageId, 1))
        ->toBeFalse();
});

it('does not increment the hit counter for a Firefox prefetch request (X-Moz: prefetch)', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Prefetch Test Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Prefetch Photo');
    @unlink($image);

    expect(pictureHitCount($imageId))
        ->toBe(0);

    $session = pictureCurlLoginSession(H::ADMIN_USER, H::ADMIN_PASS);
    $ch = curl_init($session['baseUrl'] . '/picture.php?/' . $imageId . '/category/' . $albumId);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $session['cookieJar']);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $session['cookieJar']);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [...H::testHeaders(), 'X-Moz: prefetch']);
    curl_exec($ch);
    unset($ch);
    @unlink($session['cookieJar']);

    expect(pictureHitCount($imageId))
        ->toBe(0);
});

it('remembers a picture_deriv cookie choice in the session across a follow-up request', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Deriv Cookie Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Deriv Cookie Photo');
    @unlink($image);

    $session = pictureCurlLoginSession(H::ADMIN_USER, H::ADMIN_PASS);
    $curl = $session['curl'];
    $baseUrl = $session['baseUrl'];
    $picUrl = $baseUrl . '/picture.php?/' . $imageId . '/category/' . $albumId;

    // First request carries the cookie: defaultPictureContent() reads it,
    // persists 'large' into the session, and clears the cookie itself.
    //
    // The picture_deriv cookie must be appended to the cookie *jar file*
    // itself, not passed as a manual 'Cookie:' header alongside
    // CURLOPT_COOKIEFILE -- doing the latter sends TWO separate
    // `Cookie:` header lines on the wire
    // (one from the jar's real pwg_id, one from the manual header), which
    // Apache/PHP only honors one of (whichever it picks), losing the
    // logged-in admin session entirely.
    // Writing both cookies into the same jar file lets curl's own loader
    // merge them into one real `Cookie: picture_deriv=large; pwg_id=...`
    // header, which is what a real browser sending both cookies would
    // produce.
    $jarContents = file_get_contents($session['cookieJar']);
    if ($jarContents === false) {
        throw new RuntimeException('failed to read cookie jar: ' . $session['cookieJar']);
    }
    $baseUrlParts = parse_url($baseUrl);
    $baseUrlParts_host = is_array($baseUrlParts) ? ($baseUrlParts['host'] ?? null) : null;
    $cookieHost = is_string($baseUrlParts_host) ? $baseUrlParts_host : '127.0.0.1';
    $baseUrlParts_path = is_array($baseUrlParts) ? ($baseUrlParts['path'] ?? null) : null;
    $cookiePath = is_string($baseUrlParts_path) && $baseUrlParts_path !== '' ? $baseUrlParts_path : '/';
    file_put_contents(
        $session['cookieJar'],
        $jarContents . "{$cookieHost}\tFALSE\t{$cookiePath}\tFALSE\t0\tpicture_deriv\tlarge\n"
    );

    $ch = curl_init($picUrl);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $session['cookieJar']);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $session['cookieJar']);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
    $firstBody = curl_exec($ch);
    $firstStatus = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    unset($ch);
    expect($firstStatus)
        ->toBe(200);
    expect(is_string($firstBody) ? $firstBody : '')
        ->not->toContain('Fatal error');

    // Direct proof it was actually picked up and re-set into the session
    // (not just "a follow-up request happens not to error", which the
    // second request below alone would leave ambiguous) -- 'large' is not
    // CurrentConfig::derivativeDefaultSize()'s own default ('medium'), so
    // this can only be true if defaultPictureContent() really read the
    // cookie and wrote it through SessionService::setSessionVar().
    expect(pictureSessionDerivType(pictureCookieJarSessionId($session['cookieJar'])))->toBe('large');

    // Second request, no cookie this time: the session value from the
    // first request alone must still drive defaultPictureContent()'s
    // $deriv_type, without erroring.
    $second = $curl($picUrl);
    @unlink($session['cookieJar']);
    expect($second['status'])->toBe(200);
    expect($second['body'])->not->toContain('Fatal error');
});

it('renders the related-categories breadcrumb via the single-category fast path when the photo belongs to exactly its own viewed album', function (): void {
    $page = H::asAdmin($this);
    $albumName = 'Related Cats Fast Path Album ' . uniqid();
    $album = H::createCategory($page, [
        'name' => $albumName,
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Related Cats Photo');
    @unlink($image);

    // This photo belongs to exactly one category (this album) and is
    // viewed via that same album -- PictureController::__invoke()'s
    // `count($related_categories) === 1 and $page_category !== null and
    // $related_cat0_id === $page_category_id_for_compare` fast path,
    // rendering the breadcrumb straight from $page_category['upper_names']
    // instead of the multi-category SQL-lookup branch.
    $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId);
    $page->assertSee($albumName);
    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'picture.php related-categories single-category fast path');
});

it('renders the related-categories breadcrumb for every album a multi-category photo belongs to', function (): void {
    // Distinct from the single-category fast path above:
    // `count($related_categories) === 1` is false once a photo belongs to
    // 2+ real albums, forcing PictureController's own multi-category else
    // branch (the `SELECT id, name, permalink ... WHERE id IN (...)`
    // lookup, one getCatDisplayName() call per related category) instead
    // of reading straight off $page_category['upper_names'].
    $page = H::asAdmin($this);
    $albumAName = 'Multi Cat Album A ' . uniqid();
    $albumA = H::createCategory($page, [
        'name' => $albumAName,
    ]);
    if (! is_numeric($albumA['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($albumA, true));
    }
    $albumAId = (int) $albumA['id'];

    $albumBName = 'Multi Cat Album B ' . uniqid();
    $albumB = H::createCategory($page, [
        'name' => $albumBName,
    ]);
    if (! is_numeric($albumB['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($albumB, true));
    }
    $albumBId = (int) $albumB['id'];

    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumAId, 'Multi Cat Photo');
    @unlink($image);

    // pwg.images.addSimple only ever links to its own single 'category'
    // param -- a direct image_category insert is the only way to give a
    // real, fully-wired photo a genuine SECOND album association.
    pictureAddImageToCategory($imageId, $albumBId);

    $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumAId);
    $page->assertSee($albumAName);
    $page->assertSee($albumBName);
    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'picture.php related-categories multi-category branch');
});

it('renders slideshow mode with play/repeat/period controls and a real next item', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Picture Slideshow Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $suffix = uniqid();
    $imageA = H::makeTestImage($suffix . 'a');
    $idA = H::uploadPhotoViaApi($imageA, $albumId, 'Slideshow Photo A ' . $suffix);
    @unlink($imageA);
    $imageB = H::makeTestImage($suffix . 'b');
    H::uploadPhotoViaApi($imageB, $albumId, 'Slideshow Photo B ' . $suffix);
    @unlink($imageB);

    // light_slideshow defaults true (CurrentConfig::$lightSlideshow),
    // slideshow_period/min/max/step default 4/1/10/1 and slideshow_repeat
    // defaults true, none overridden by this fixture --
    // so viewing photo A (which has a real next item, B) with slideshow=1
    // exercises the play=true auto-advance branch, both period-step
    // links (4-1=3 and 4+1=5 both stay within [1,10]), and the
    // repeat=true branch, all via slideshow.latte + picture_nav_buttons.latte.
    $page = H::navigateOk($page, '/picture.php?/' . $idA . '/category/' . $albumId . '&slideshow=1');

    $page->assertSee('stop the slideshow');
    // picture_nav_buttons.latte's own control labels (pwg-button-text spans)
    // are icon-only, CSS-hidden text, so
    // assertSee() (visible text only) never finds them; a raw-content
    // check is the right tool, same precedent as BatchManagerUnitPageRenderer
    // Test's own title-attribute case. The loaded en_UK catalog also
    // rephrases these from their literal PHP source msgids (e.g. "Reduce
    // diaporama speed" -> "Reduce slideshow speed").
    $body = H::rawWebpage($page)->content();
    expect($body)
        ->toContain('Reduce slideshow speed');
    expect($body)
        ->toContain('Increase slideshow speed');
    expect($body)
        ->toContain('Pause slideshow');
    expect($body)
        ->toContain('Do not repeat slideshow');
    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'picture.php slideshow mode');
});

it('shows access-denied via the flat "all items" view when the photo\'s only album is private', function (): void {
    // PictureController::__invoke()'s own flat-view branch
    // (`$page_section === 'categories' and $page_category === null`) is
    // only reachable when the image genuinely isn't part of the current
    // flat item list -- engineered here deterministically by making its
    // one and only album private (excluded from the permission-filtered
    // flat query for a non-admin viewer, regardless of ambient
    // site-wide image counts/pagination, unlike a bare picture.php
    // request -- see this file's own top-of-file comment on exactly that
    // fragility). The bare `/{imageId}` URL form (no `/category/`) is
    // what UrlService::parseSectionUrl() turns into `page['flat'] = true`
    // with no category
    // (SectionPopulator.php's own "access a picture only by id, file or
    // id-file without given section" comment).
    $adminSession = pictureCurlLoginSession(H::ADMIN_USER, H::ADMIN_PASS);
    $curl = $adminSession['curl'];
    $baseUrl = $adminSession['baseUrl'];

    $statusBody = H::curlApi($adminSession['cookieJar'], 'GET', '/api/v1/session');
    $decodedStatus = json_decode($statusBody, true);
    $pwgTokenRaw = is_array($decodedStatus) ? ($decodedStatus['pwgToken'] ?? null) : null;
    $pwgToken = is_string($pwgTokenRaw) || is_int($pwgTokenRaw) ? (string) $pwgTokenRaw : '';
    expect($pwgToken)
        ->not->toBe('');

    $albumBody = H::curlApi($adminSession['cookieJar'], 'POST', '/api/v1/categories', [
        'name' => 'Flat View Denied Album ' . uniqid(),
    ], $pwgToken);
    $albumData = json_decode($albumBody, true);
    $albumIdRaw = is_array($albumData) ? ($albumData['id'] ?? null) : null;
    $albumId = is_numeric($albumIdRaw) ? (int) $albumIdRaw : 0;
    expect($albumId)
        ->toBeGreaterThan(0);

    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Flat View Denied Photo');
    @unlink($image);

    H::setCategoryPrivate($albumId, true);

    try {
        $regularSession = pictureCurlLoginSession('regular_user', 'regular_user_pass');
        $result = $regularSession['curl']($baseUrl . '/picture.php?/' . $imageId);
        @unlink($regularSession['cookieJar']);

        expect($result['status'])->toBe(401);
        expect($result['body'])->toContain('You are not authorized to access the requested page');
    } finally {
        H::setCategoryPrivate($albumId, false);
        @unlink($adminSession['cookieJar']);
    }
});

it('shows access-denied when a viewed image has no category association at all (the "try to access it differently" query finds nothing)', function (): void {
    // Distinct from the flat-view branch above: this is reached via a
    // real, specific /category/X view (so $page_category !== null,
    // skipping the flat-view check entirely) once the image is confirmed
    // not-filtered and not-over-level -- PictureController's own
    // "try to see if we can access it differently" fetchOne() query joins
    // image_category, and a real image genuinely orphaned from every
    // category (the same state an image fully removed from its albums,
    // or a still lounge-parked upload, would be in) makes that query find
    // nothing, hitting access-denied() rather than either the best_rated
    // fallback or the redirect this file's other tests cover for the
    // "found via a different category" case.
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Orphaned Image Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Orphaned Image Photo');
    @unlink($image);

    pictureRemoveImageFromAllCategories($imageId);

    $adminSession = pictureCurlLoginSession(H::ADMIN_USER, H::ADMIN_PASS);
    $result = $adminSession['curl']($adminSession['baseUrl'] . '/picture.php?/' . $imageId . '/category/' . $albumId);
    @unlink($adminSession['cookieJar']);

    expect($result['status'])->toBe(401);
    expect($result['body'])->toContain('You are not authorized to access the requested page');
});

it('appends the current photo into best_rated\'s own item list instead of redirecting, when it is accessible but not actually top-rated', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Best Rated Fallback Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Best Rated Fallback Photo');
    @unlink($image);

    // No rate config/data manipulation needed: SectionPopulator's own
    // best_rated query (`ORDER BY rating_score DESC`) only ever returns
    // images with at least one real rate row -- a brand-new,
    // never-rated photo is guaranteed to not already be part of that
    // list. Viewing it via /best_rated therefore deterministically
    // exercises PictureController's own best_rated-specific fallback
    // (`$rank_of[$image_id] = count($items); $items[] = $image_id;` then
    // SectionContextRegistry::set()) rather than the redirect the exact
    // same "not in the section's item list, but accessible via a
    // different category" state hits for every other section (covered by
    // the sibling test below) -- a real 200 render, not a 30x.
    $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/best_rated');
    $page->assertSee('Best Rated Fallback Photo');
    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'picture.php best_rated fallback');
});

it('redirects to the canonical flat picture URL when the image is not part of the requested section (301 for recent_pics, 302 for most_visited)', function (): void {
    $adminSession = pictureCurlLoginSession(H::ADMIN_USER, H::ADMIN_PASS);
    $curl = $adminSession['curl'];
    $baseUrl = $adminSession['baseUrl'];

    $statusBody = H::curlApi($adminSession['cookieJar'], 'GET', '/api/v1/session');
    $decodedStatus = json_decode($statusBody, true);
    $pwgTokenRaw = is_array($decodedStatus) ? ($decodedStatus['pwgToken'] ?? null) : null;
    $pwgToken = is_string($pwgTokenRaw) || is_int($pwgTokenRaw) ? (string) $pwgTokenRaw : '';
    expect($pwgToken)
        ->not->toBe('');

    $albumBody = H::curlApi($adminSession['cookieJar'], 'POST', '/api/v1/categories', [
        'name' => 'Redirect Section Album ' . uniqid(),
    ], $pwgToken);
    $albumData = json_decode($albumBody, true);
    $albumIdRaw = is_array($albumData) ? ($albumData['id'] ?? null) : null;
    $albumId = is_numeric($albumIdRaw) ? (int) $albumIdRaw : 0;
    expect($albumId)
        ->toBeGreaterThan(0);

    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Redirect Section Photo');
    @unlink($image);

    // Backdated well outside any real "recent" window (same technique as
    // the "filtered" test above) AND never viewed (hit stays 0, the
    // freshly-inserted default) -- excludes this SAME photo from both the
    // recent_pics query (date_available-gated) and the most_visited query
    // (`WHERE hit > 0`) at once, so one photo deterministically drives
    // both assertions below without an intervening view bumping its hit
    // count.
    pictureSetImageDateAvailable($imageId, date('Y-m-d H:i:s', strtotime('-90 days')));

    // Raw curl, no auto-follow: need the real 30x status code + Location
    // header, not fetch(manual)'s always-opaque status.
    $fetchRedirect = static function (string $url) use ($adminSession): array {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $adminSession['cookieJar']);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $adminSession['cookieJar']);
        curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        unset($ch);

        return [
            'status' => $status,
            'location' => is_string($location) ? $location : '',
        ];
    };

    $recent = $fetchRedirect($baseUrl . '/picture.php?/' . $imageId . '/recent_pics');
    $mostVisited = $fetchRedirect($baseUrl . '/picture.php?/' . $imageId . '/most_visited');
    @unlink($adminSession['cookieJar']);

    expect($recent['status'])->toBe(301);
    expect($recent['location'])->toContain('/' . $imageId . '/categories');
    expect($mostVisited['status'])->toBe(302);
    expect($mostVisited['location'])->toContain('/' . $imageId . '/categories');
});

it('flashes an admin-authorization message via the session when a non-admin\'s comment edit needs moderation', function (): void {
    // CommentService::updateComment()'s own 'moderate' branch requires
    // BOTH comments_validation=true AND the editor NOT being an admin --
    // and canManageComment('edit', ...) additionally requires
    // user_can_edit_comment=true for a non-admin to manage their OWN
    // comment at all (both false by default in this fixture).
    $snapshot = H::snapshotConfig(['comments_validation', 'user_can_edit_comment']);
    H::setConfigValue('comments_validation', 'true');
    H::setConfigValue('user_can_edit_comment', 'true');

    try {
        $adminSession = pictureCurlLoginSession(H::ADMIN_USER, H::ADMIN_PASS);
        $baseUrl = $adminSession['baseUrl'];
        $adminStatusBody = H::curlApi($adminSession['cookieJar'], 'GET', '/api/v1/session');
        $adminDecodedStatus = json_decode($adminStatusBody, true);
        $adminPwgTokenRaw = is_array($adminDecodedStatus) ? ($adminDecodedStatus['pwgToken'] ?? null) : null;
        $adminPwgToken = is_string($adminPwgTokenRaw) || is_int($adminPwgTokenRaw) ? (string) $adminPwgTokenRaw : '';
        expect($adminPwgToken)
            ->not->toBe('');

        $albumBody = H::curlApi($adminSession['cookieJar'], 'POST', '/api/v1/categories', [
            'name' => 'Moderate Flash Album ' . uniqid(),
        ], $adminPwgToken);
        $albumData = json_decode($albumBody, true);
        $albumIdRaw = is_array($albumData) ? ($albumData['id'] ?? null) : null;
        $albumId = is_numeric($albumIdRaw) ? (int) $albumIdRaw : 0;
        expect($albumId)
            ->toBeGreaterThan(0);

        $image = H::makeTestImage(uniqid());
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'Moderate Flash Photo');
        @unlink($image);
        @unlink($adminSession['cookieJar']);

        // author_id=3 (regular_user, the same real account this file's
        // other edit_comment tests already use) -- editing their OWN
        // comment is what canManageComment('edit', ...) allows for a
        // non-admin.
        $commentId = pictureInsertComment($imageId, 'moderate-me-' . uniqid(), 'Original moderate content.', true, 3);

        $userSession = pictureCurlLoginSession('regular_user', 'regular_user_pass');
        $curl = $userSession['curl'];

        $statusBody = H::curlApi($userSession['cookieJar'], 'GET', '/api/v1/session');
        $decodedStatus = json_decode($statusBody, true);
        $pwgTokenRaw = is_array($decodedStatus) ? ($decodedStatus['pwgToken'] ?? null) : null;
        $pwgToken = is_string($pwgTokenRaw) || is_int($pwgTokenRaw) ? (string) $pwgTokenRaw : '';
        expect($pwgToken)
            ->not->toBe('');

        $editUrl = $baseUrl . '/picture.php?/' . $imageId . '/category/' . $albumId
            . '&action=edit_comment&comment_to_edit=' . $commentId;

        $getResult = $curl($editUrl);
        if (preg_match('/name="key" value="([^"]+)"/', $getResult['body'], $matches) !== 1) {
            throw new RuntimeException('Could not find the edit-comment form\'s hidden key field in: ' . $getResult['body']);
        }
        $key = html_entity_decode($matches[1]);

        // Same >=2 real wall-clock second minimum-key-age wait this file's
        // other edit_comment tests already use.
        sleep(3);

        $postResult = $curl($editUrl, [
            'content' => 'Moderated content update ' . uniqid(),
            'website_url' => '',
            'key' => $key,
            'pwg_token' => $pwgToken,
        ], false);
        expect($postResult['status'])->toBe(302);

        // 'moderate' falls through to 'validate' in PictureController's own
        // switch (no `break` between the two cases) -- BOTH messages are
        // queued via $_SESSION['page_infos'][], and $perform_redirect ends
        // up true from the 'validate' case, so a real redirect happens.
        // Follow it for real (not fetch(manual)'s opaque status) to prove
        // HtmlService::flushMessageMode() -- which reads
        // `$_SESSION['page_' . $mode]` generically, not the literal string
        // 'page_infos' -- actually surfaces this on the next page, the
        // same mechanism PasswordController's own fix
        // (a873f5ca7d) relies on for its own $_SESSION['page_errors']
        // flash. $url_self (the real redirect target, computed early in
        // __invoke() as a bare duplicatePictureUrl(), before the
        // action-handling switch ever runs) has no action/comment params
        // of its own -- match it exactly rather than reusing $editUrl.
        $plainPictureUrl = $baseUrl . '/picture.php?/' . $imageId . '/category/' . $albumId;
        $finalBody = $curl($plainPictureUrl)['body'];

        $db = pictureDbConnect();
        $row = H::fetchAssocOrFail($db, sprintf('SELECT content, validated FROM comments WHERE id = %d', $commentId));
        H::dbClose($db);
        @unlink($userSession['cookieJar']);

        expect(H::dbToBool($row['validated']) ? 1 : 0)->toBe(0);
        expect($finalBody)
            ->toContain('An administrator must authorize your comment before it becomes visible.');
        expect($finalBody)
            ->toContain('Your comment has been registered');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('logs a PHP warning and still renders when a plugin-registered user_comment_check handler returns an unrecognized action', function (): void {
    // CommentService::updateComment() only ever produces 'validate',
    // 'moderate' or 'reject' on its own -- the `default:
    // trigger_error(E_USER_WARNING)` case in PictureController's own
    // switch is a defensive guard against a MISBEHAVING plugin handler of
    // the `user_comment_check` event (its own contract explicitly
    // requires one of those 3 strings back), not reachable through normal
    // request input alone. Reaching it for real needs a real plugin --
    // PluginConfig\PluginRegistry::bootActive() boots every DB-active
    // plugin's
    // main class on every request, the same real mechanism a genuine
    // misbehaving 3rd-party plugin would use. Content-marker-gated (only
    // ever intervenes for THIS test's own unique comment content) so it's
    // a complete no-op for every other
    // concurrent request against this shared dev server while active, and
    // both the DB row and the plugin file are removed again in `finally`.
    $pluginId = 'pwgtest-picture-bogus-comment-action';
    $pluginDir = dirname(__DIR__, 2) . '/plugins/' . $pluginId;
    $marker = 'PWGTEST_BOGUS_ACTION_MARKER_' . uniqid();

    pictureWriteFixturePlugin($pluginDir, <<<PHP
        \Piwigo\Tests\Support\EventDispatcherTestFactory::get()->addTypedHandler(
            \\Piwigo\\Comment\\Event\\UserCommentCheck::class,
            static function (\\Piwigo\\Comment\\Event\\UserCommentCheck \$event): void {
                \$content = is_string(\$event->comm['content'] ?? null) ? \$event->comm['content'] : '';
                if (str_contains(\$content, '{$marker}')) {
                    \$event->commentAction = 'this-is-not-a-real-comment-action';
                }
            }
        );
        PHP);

    $pluginDb = pictureDbConnect();
    H::dbQuery($pluginDb, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0.0')", $pluginId));
    H::dbClose($pluginDb);
    // The DB `config` cache pool has no bearing on plugin *loading* itself
    // (PluginConfig\PluginRegistry::bootActive() always re-queries active
    // plugins fresh, no cache layer of its own) -- no cache-clear needed
    // here, unlike the config-param tests elsewhere in this suite.

    // canManageComment('edit', ...) requires user_can_edit_comment=true for
    // a non-admin to manage their OWN comment at all (false by default in
    // this fixture) -- same requirement as this file's own "flashes an
    // admin-authorization message..." test above. Without it, regular_user
    // never reaches the edit_comment action at all and the page silently
    // falls back to rendering the plain "Add a comment" form instead.
    $configSnapshot = H::snapshotConfig(['user_can_edit_comment']);
    H::setConfigValue('user_can_edit_comment', 'true');

    try {
        $adminSession = pictureCurlLoginSession(H::ADMIN_USER, H::ADMIN_PASS);
        $baseUrl = $adminSession['baseUrl'];
        $adminStatusBody = H::curlApi($adminSession['cookieJar'], 'GET', '/api/v1/session');
        $adminDecodedStatus = json_decode($adminStatusBody, true);
        $adminPwgTokenRaw = is_array($adminDecodedStatus) ? ($adminDecodedStatus['pwgToken'] ?? null) : null;
        $adminPwgToken = is_string($adminPwgTokenRaw) || is_int($adminPwgTokenRaw) ? (string) $adminPwgTokenRaw : '';
        expect($adminPwgToken)
            ->not->toBe('');

        $albumBody = H::curlApi($adminSession['cookieJar'], 'POST', '/api/v1/categories', [
            'name' => 'Bogus Action Album ' . uniqid(),
        ], $adminPwgToken);
        $albumData = json_decode($albumBody, true);
        $albumIdRaw = is_array($albumData) ? ($albumData['id'] ?? null) : null;
        $albumId = is_numeric($albumIdRaw) ? (int) $albumIdRaw : 0;
        expect($albumId)
            ->toBeGreaterThan(0);

        $image = H::makeTestImage(uniqid());
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'Bogus Action Photo');
        @unlink($image);
        @unlink($adminSession['cookieJar']);

        $commentId = pictureInsertComment($imageId, 'bogus-action-' . uniqid(), 'Original bogus-action content.', true, 3);

        $userSession = pictureCurlLoginSession('regular_user', 'regular_user_pass');
        $curl = $userSession['curl'];

        $statusBody = H::curlApi($userSession['cookieJar'], 'GET', '/api/v1/session');
        $decodedStatus = json_decode($statusBody, true);
        $pwgTokenRaw = is_array($decodedStatus) ? ($decodedStatus['pwgToken'] ?? null) : null;
        $pwgToken = is_string($pwgTokenRaw) || is_int($pwgTokenRaw) ? (string) $pwgTokenRaw : '';
        expect($pwgToken)
            ->not->toBe('');

        $editUrl = $baseUrl . '/picture.php?/' . $imageId . '/category/' . $albumId
            . '&action=edit_comment&comment_to_edit=' . $commentId;

        $getResult = $curl($editUrl);
        if (preg_match('/name="key" value="([^"]+)"/', $getResult['body'], $matches) !== 1) {
            throw new RuntimeException('Could not find the edit-comment form\'s hidden key field in: ' . $getResult['body']);
        }
        $key = html_entity_decode($matches[1]);
        sleep(3);

        $postResult = $curl($editUrl, [
            'content' => 'Bogus action content update ' . $marker,
            'website_url' => '',
            'key' => $key,
            'pwg_token' => $pwgToken,
        ]);
        @unlink($userSession['cookieJar']);

        // The switch's `default` case has no `$perform_redirect = true` of
        // its own (only 'validate' sets that) -- execution falls straight
        // through to rendering the SAME picture page again (200, still in
        // edit-comment mode), not a redirect.
        expect($postResult['status'])->toBe(200);
        expect($postResult['body'])->not->toContain('Fatal error');

        // CommentService::updateComment()'s own DB write
        // (`'validated' => $commentAction === 'validate'`) still runs
        // unconditionally for any $commentAction !== 'reject' -- the
        // content update itself is real, only 'validated' differs from
        // the normal 'validate'/'moderate' outcomes (also both false/0
        // here, so this alone wouldn't distinguish the branch -- the 200
        // instead of the redirect this file's other successful-edit tests
        // get is the real, distinguishing signal above).
        $db = pictureDbConnect();
        $row = H::fetchAssocOrFail($db, sprintf('SELECT content, validated FROM comments WHERE id = %d', $commentId));
        H::dbClose($db);
        expect($row['content'])->toBe('Bogus action content update ' . $marker);
        expect(H::dbToBool($row['validated']) ? 1 : 0)->toBe(0);
    } finally {
        $cleanupDb = pictureDbConnect();
        H::dbQuery($cleanupDb, sprintf("DELETE FROM plugins WHERE id = '%s'", $pluginId));
        H::dbClose($cleanupDb);
        pictureRemoveFixturePlugin($pluginDir);
        H::restoreConfig($configSnapshot);
    }
});

it('builds a download-format list with the URL fallback, strtoupper() label fallback, and MB-formatted filesize for a real extra format row', function (): void {
    // isFormatsEnabled() defaults false; pictureDownloadIcon() defaults
    // true and enabled_high defaults true for the fixture admin (both
    // already exercised implicitly by every other passing test, so left
    // alone here) -- only enable_formats needs a real snapshot/restore.
    $snapshot = H::snapshotConfig(['enable_formats']);
    H::setConfigValue('enable_formats', 'true');

    try {
        $page = H::asAdmin($this);
        $album = H::createCategory($page, [
            'name' => 'Format List Album ' . uniqid(),
        ]);
        if (! is_numeric($album['id'] ?? null)) {
            throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $album['id'];
        $image = H::makeTestImage(uniqid());
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'Format List Photo');
        @unlink($image);

        // A real image_format row with no lang catalog entry for
        // 'format WEBP' (no core language file, en_UK or
        // otherwise, in this repo or the 16.x reference tree defines any
        // 'format <EXT>' msgid -- Lang::has()'s own true branch is
        // realistically unreachable with the shipped catalogs, so this
        // deliberately exercises the strtoupper() fallback, the one real
        // path). 2048 KB -> exactly 2.0MB (2048/1024) once
        // sprintf('%.1fMB', ...) formats it.
        //
        // PictureController.php's own `Lang::t($lang_key)` call inside that
        // true branch is left genuinely uncovered by this suite on purpose
        // -- reaching it for real would need a fake plugin-installed
        // language file providing a 'format <EXT>' translation, which
        // exercises Lang::load()'s plugin-.po-loading machinery far more
        // than it exercises anything in PictureController itself, for a
        // single-line label-formatting branch with no other behavioral
        // consequence. Left uncovered rather than built around a synthetic
        // translation catalog.
        pictureInsertImageFormat($imageId, 'webp', 2048);

        $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId);
        $body = H::rawWebpage($page)->content();

        // The array_unshift()-ed original entry already has its own real
        // download_url (action.php?id=...&part=e&download) set directly
        // on the literal -- the format-fallback branch
        // (`action.php?format=<id>&amp;download`) only ever applies to
        // the *other* rows built from Projection\ImageFormat::toArray(),
        // which never has a 'download_url' key at all.
        expect($body)
            ->toMatch('/action\.php\?format=\d+&amp;download/');
        expect($body)
            ->toContain('>WEBP<span class="downloadformatDetails"> (2.0MB)</span>');
        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'picture.php format list');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('assigns PDF_VIEWER_FILESIZE_THRESHOLD/PDF_NB_PAGES and renders the inline PDF viewer for a PDF-format image', function (): void {
    // No Browser-suite fixture already produces a real PDF upload
    // end-to-end (UploadServiceTest's own uploadFilePdf() coverage is
    // Unit-level, calling the static conversion method directly, never
    // through a live HTTP request) -- and CurrentConfig::
    // uploadFormAllTypes() defaults false, gating the tus upload pipeline's
    // own non-image branch. Rather than flipping that global config for
    // the whole shared dev server (affecting every concurrent request
    // while active) just to exercise ONE unrelated template-var branch,
    // this uploads a real, fully-wired JPEG through the normal path (real
    // category/permission/thumbnail wiring) and then swaps its `file`/
    // `path`/`filesize` DB columns to point at a real PDF placed at that
    // same on-disk location -- exactly what PictureController itself
    // reads (extension + path + filesize), independent of how the image
    // got there.
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'PDF Viewer Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'PDF Viewer Photo');
    @unlink($image);

    $relativePath = H::imagePath($imageId);
    $root = dirname(__DIR__, 2) . '/';
    $absoluteJpgPath = $root . $relativePath;
    $absolutePdfPath = preg_replace('/\.[^.\/]+$/', '.pdf', $absoluteJpgPath);
    $relativePdfPath = preg_replace('/\.[^.\/]+$/', '.pdf', $relativePath);
    if (! is_string($absolutePdfPath) || ! is_string($relativePdfPath)) {
        throw new RuntimeException('preg_replace() failed to build the .pdf path');
    }

    // Same real ImageMagick CLI conversion UploadServiceTest's own
    // uploadFilePdf() coverage uses (`convert`, confirmed on PATH) --
    // produces a genuine PDF with real internal `/Page` markers
    // ImageService::countPdfPages() can actually count, not a fake
    // "%PDF" byte-stub.
    $sourcePng = sys_get_temp_dir() . '/pwg_pdf_viewer_source_' . uniqid() . '.png';
    exec('convert -size 40x40 xc:blue ' . escapeshellarg($sourcePng) . ' 2>&1', $out1, $status1);
    if ($status1 !== 0) {
        throw new RuntimeException('convert (sample PNG) failed: ' . implode("\n", $out1));
    }
    exec('convert ' . escapeshellarg($sourcePng) . ' ' . escapeshellarg($absolutePdfPath) . ' 2>&1', $out2, $status2);
    @unlink($sourcePng);
    if ($status2 !== 0) {
        throw new RuntimeException('convert (PNG -> PDF) failed: ' . implode("\n", $out2));
    }
    $pdfFilesizeBytes = filesize($absolutePdfPath);
    if ($pdfFilesizeBytes === false) {
        throw new RuntimeException('filesize() failed for ' . $absolutePdfPath);
    }
    // Matches UploadService::pwgImageInfos()'s own floor(bytes/1024)
    // convention for the images.filesize column (KB, not bytes) -- well
    // under the 5*1024 KB default pdf_viewer_filesize_threshold either
    // way, so the inline <embed> branch (not the "too large" one) is the
    // one genuinely exercised here.
    $pdfFilesizeKb = (int) floor($pdfFilesizeBytes / 1024);

    $pdfFilename = basename($relativePdfPath);
    $db = pictureDbConnect();
    H::dbQuery($db, sprintf("UPDATE images SET file = '%s', path = '%s', filesize = %d WHERE id = %d", H::dbEscape($db, $pdfFilename), H::dbEscape($db, $relativePdfPath), $pdfFilesizeKb, $imageId));
    H::dbClose($db);

    try {
        $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId);
        $body = H::rawWebpage($page)->content();

        expect($body)
            ->toContain('<embed src="' . $relativePdfPath . '" type="application/pdf"');
        expect($body)
            ->not->toContain('too large to display');
        expect($body)
            ->toContain('<dt>Pages</dt>');
        // countPdfPages()'s own preg_match_all('/\/Page\W/', ...) on a
        // real single-page ImageMagick-converted PDF is always exactly 1.
        expect($body)
            ->toMatch('/<dt>Pages<\/dt>\s*<dd>1<\/dd>/');
        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'picture.php PDF viewer');
    } finally {
        @unlink($absolutePdfPath);
    }
});

it('renders the legend/author/creation-date info block for a photo with a real comment, author, and creation date', function (): void {
    // None of this file's other tests ever set the image's OWN comment
    // (caption)/author/date_creation columns via pwg.images.setInfo --
    // distinct from the comments table entries this file's
    // edit_comment/delete_comment/validate_comment tests exercise
    // elsewhere. Every prior test's freshly-uploaded photo leaves those 3
    // columns NULL, so PictureController::__invoke()'s own
    // `is_string(...) && ... !== ''` guards around the "legend"/"author"/
    // "creation date" blocks always fail, leaving COMMENT_IMG/
    // INFO_AUTHOR/INFO_CREATION_DATE entirely unbuilt.
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Legend Info Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Legend Info Photo');
    @unlink($image);

    $commentMarker = 'Legend Comment ' . uniqid();
    $authorName = 'Legend Author ' . uniqid();
    // singleValueMode defaults 'fillIfEmpty' (ImageUpdateInput's own
    // default) -- a fresh upload's author/comment/date_creation columns
    // are all NULL, so the default mode alone is enough here, no need to
    // pass single_value_mode explicitly.
    H::updateImageInfo($page, [
        'image_id' => (string) $imageId,
        'comment' => $commentMarker,
        'author' => $authorName,
        'date_creation' => '2020-05-15 10:00:00',
    ]);

    // picture_informations defaults author=true/created_on=true (per the
    // fixture's own config row) -- not overridden here, same as this
    // file's own "format list" test relies on for its own tags=true
    // default.
    $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId);
    $body = H::rawWebpage($page)->content();

    // legend (the image's own caption, rendered through the
    // RenderElementDescription event -> HtmlService::pwgNl2br())
    expect($body)
        ->toContain('class="imageComment"');
    expect($body)
        ->toContain($commentMarker);

    // author, in the body...
    $page->assertSee($authorName);
    // ...and in the <head>, which reads INFO_AUTHOR off
    // PictureHeaderPageContext rather than PictureView -- layout.latte
    // renders before PictureView is ever constructed, so the two are
    // separate assigns and only this assertion covers the second one.
    expect($body)
        ->toMatch('/<meta\s+name="author"\s+content="' . preg_quote($authorName, '/') . '"\s*>/');

    // creation date: DateHelper::formatDate() always includes the year,
    // and PictureController's own chronology link is built from
    // explode('-', substr($date_creation, 0, 10)) fed into
    // UrlService::makeIndexUrl()'s own chronology segment
    // (`{field}-{style}-{view}-{y}-{m}-{d}`, per
    // UrlService::addChronologyAndStartToUrl()).
    expect($body)
        ->toContain('2020');
    expect($body)
        ->toMatch('/<a href="[^"]*created-monthly-list-2020-05-15[^"]*" rel="nofollow">/');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'picture.php legend/author/creation-date info block');
});

it('wraps around to the first photo via meta-refresh when a repeating slideshow reaches the last item', function (): void {
    // Distinct from this file's own "renders slideshow mode..." test
    // above, which views the FIRST item of its album (a real next item,
    // so $next_item !== null takes the plain 'next' branch) --
    // PictureController::__invoke()'s own repeat-wrap branch
    // (`$next_item === null and $slideshow_params['repeat'] and
    // $first_item !== null -> $id_pict_redirect = 'first'`) only fires
    // when viewing the LAST item of a multi-photo section with repeat
    // enabled, engineered here the same image_order=1-then-view technique
    // as this file's own nav test uses to get a deterministic rank.
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Slideshow Wrap Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $suffix = uniqid();
    $imageA = H::makeTestImage($suffix . 'a');
    $idA = H::uploadPhotoViaApi($imageA, $albumId, 'Wrap Photo A ' . $suffix);
    @unlink($imageA);
    $imageB = H::makeTestImage($suffix . 'b');
    $idB = H::uploadPhotoViaApi($imageB, $albumId, 'Wrap Photo B ' . $suffix);
    @unlink($imageB);

    $cookieJar = tempnam(sys_get_temp_dir(), 'pwg_slideshow_wrap_');
    if ($cookieJar === false) {
        throw new RuntimeException('tempnam failed');
    }

    // image_order=1 is "Photo title, A -> Z" -- same session-persisted
    // ordering technique as the nav test above, guaranteeing B is the
    // section's real LAST rank (no next item of its own).
    pictureGetWithCookies($cookieJar, '/index.php?/category/' . $albumId . '&image_order=1');

    $body = pictureGetWithCookies($cookieJar, '/picture.php?/' . $idB . '/category/' . $albumId . '&slideshow=1');
    @unlink($cookieJar);

    expect($body)
        ->not->toContain('Fatal error');
    // play defaults true (ImageService::getDefaultSlideshowParams()) and
    // repeat defaults true in this fixture (same default this file's own
    // "renders slideshow mode..." test above already relies on) -- viewing
    // B (no next item) therefore deterministically wraps to A, producing a
    // real <meta http-equiv="refresh"> pointing at A's own picture URL.
    // http-equiv= and content= sit on separate template-source lines
    // after P32's reformat, so only the content= attribute (still intact
    // on its own line) is regex-matched here.
    expect($body)
        ->toContain('http-equiv="refresh"');
    expect($body)
        ->toMatch('/content="\d+;url=[^"]*\/' . $idA . '\/[^"]*"/');
    // P44-C: page_refresh['U_REFRESH'] is now printed with no |noescape,
    // trusting Latte's own auto-escape -- PictureController's own
    // addUrlParams() call must pass argSeparator: '&' explicitly (its
    // default is '&amp;'), or a pre-encoded '&amp;' here would be
    // re-escaped to '&amp;amp;' by that auto-escape at print time.
    expect($body)
        ->not->toContain('&amp;amp;');
});

it('falls back to the medium derivative size, without warnings, when the picture_deriv session value is corrupted to a non-string', function (): void {
    // SessionService::getPictureDeriv() itself never legitimately returns
    // anything but a real string or null -- the only writer,
    // defaultPictureContent()'s own $_COOKIE['picture_deriv'] handling,
    // already guards with is_string() before calling setSessionVar(), and
    // CurrentConfig::derivativeDefaultSize() (the `?? ...` fallback every
    // real caller applies when getPictureDeriv() returns null) is declared
    // `string`. So
    // PictureController's own `! is_string($deriv_type)` fallback (in
    // BOTH __invoke()'s own prefetch block and defaultPictureContent()
    // itself) can only be reached through a genuinely corrupted session
    // row -- engineered here the same raw-DB-write-between-two-requests
    // technique this file's own "remembers a picture_deriv cookie choice"
    // test above uses, and the same PHP session serialize_handler format
    // (`name|serialized_value;`) pictureSessionDerivType() already parses.
    $session = pictureCurlLoginSession(H::ADMIN_USER, H::ADMIN_PASS);
    $curl = $session['curl'];
    $baseUrl = $session['baseUrl'];

    $statusBody = H::curlApi($session['cookieJar'], 'GET', '/api/v1/session');
    $decodedStatus = json_decode($statusBody, true);
    $pwgTokenRaw = is_array($decodedStatus) ? ($decodedStatus['pwgToken'] ?? null) : null;
    $pwgToken = is_string($pwgTokenRaw) || is_int($pwgTokenRaw) ? (string) $pwgTokenRaw : '';
    expect($pwgToken)
        ->not->toBe('');

    $albumBody = H::curlApi($session['cookieJar'], 'POST', '/api/v1/categories', [
        'name' => 'Deriv Corrupt Album ' . uniqid(),
    ], $pwgToken);
    $albumData = json_decode($albumBody, true);
    $albumIdRaw = is_array($albumData) ? ($albumData['id'] ?? null) : null;
    $albumId = is_numeric($albumIdRaw) ? (int) $albumIdRaw : 0;
    expect($albumId)
        ->toBeGreaterThan(0);

    $suffix = uniqid();
    $imageA = H::makeTestImage($suffix . 'a');
    $idA = H::uploadPhotoViaApi($imageA, $albumId, 'Deriv Corrupt Photo A ' . $suffix);
    @unlink($imageA);
    $imageB = H::makeTestImage($suffix . 'b');
    H::uploadPhotoViaApi($imageB, $albumId, 'Deriv Corrupt Photo B ' . $suffix);
    @unlink($imageB);

    // image_order=1 (title A-Z), same technique as this file's own nav
    // test, so idA deterministically has a real next item -- required for
    // __invoke()'s own `isset($picture['next'])`-gated prefetch branch.
    $curl($baseUrl . '/index.php?/category/' . $albumId . '&image_order=1');

    $sessionId = pictureCookieJarSessionId($session['cookieJar']);
    $db = pictureDbConnect();
    // An int is the simplest genuinely non-string corruption -- appended
    // (not replacing any existing key), since neither the login flow nor
    // the plain index.php visit above ever writes a real
    // 'pwg_picture_deriv' session var of their own.
    H::dbQuery($db, sprintf("UPDATE sessions SET data = CONCAT(data, 'pwg_picture_deriv|i:999;') WHERE id LIKE '%%%s'", H::dbEscape($db, $sessionId)));
    H::dbClose($db);

    $result = $curl($baseUrl . '/picture.php?/' . $idA . '/category/' . $albumId);
    @unlink($session['cookieJar']);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Fatal error');
    expect($result['body'])->not->toContain('Warning:');
    expect($result['body'])->not->toContain('Notice:');
    // Both fallbacks land on ImageStdParams::MEDIUM -- the prefetch
    // <link> (__invoke()'s own branch, reading the SAME corrupted session
    // var) still gets a real, well-formed derivative URL rather than an
    // "Illegal offset type"/array-to-string failure on
    // `$picture['next']['derivatives'][$prefetch_deriv_type]`. rel= and
    // href= sit on separate template-source lines after P32's reformat,
    // so this checks a real href= attribute follows shortly after the
    // rel="prefetch" marker rather than hardcoding the literal
    // multi-line gap, which a future reformat could reshape again.
    $prefetchPos = strpos($result['body'], 'rel="prefetch"');
    expect($prefetchPos)
        ->not->toBeFalse();
    assert($prefetchPos !== false);
    expect(substr($result['body'], $prefetchPos, 300))->toMatch('/href="[^"]+"/');
});

it('short-circuits the default element-content renderer when an earlier render_element_content plugin handler already produced content', function (): void {
    // PictureController::__invoke() registers defaultPictureContent() as
    // its OWN RenderElementContent handler at EventDispatcher::
    // addTypedHandler()'s default priority (50). A plugin registering at a
    // LOWER priority runs BEFORE it and, if it sets non-empty content,
    // defaultPictureContent()'s own `if ($event->content !== '') { return
    // $event; }` guard (its very first statement) short-circuits instead
    // of ever building derivatives/assigning template vars -- the same
    // real-plugin technique this file's own "logs a PHP warning..." test
    // above uses for a different event, content-marker-gated by this
    // photo's own real image_id so it's a no-op for every other concurrent
    // request against this shared dev server while active.
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Element Content Hook Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Element Content Hook Photo');
    @unlink($image);

    $pluginId = 'pwgtest-picture-element-content-hook';
    $pluginDir = dirname(__DIR__, 2) . '/plugins/' . $pluginId;
    $marker = 'PWGTEST_ELEMENT_CONTENT_MARKER_' . uniqid();

    pictureWriteFixturePlugin($pluginDir, <<<PHP
        \Piwigo\Tests\Support\EventDispatcherTestFactory::get()->addTypedHandler(
            \\Piwigo\\Controller\\Event\\RenderElementContent::class,
            static function (\\Piwigo\\Controller\\Event\\RenderElementContent \$event): \\Piwigo\\Controller\\Event\\RenderElementContent {
                if (\$event->currentPicture->image->id->value === {$imageId}) {
                    \$event->content = '{$marker}';
                }

                return \$event;
            },
            10
        );
        PHP);

    $pluginDb = pictureDbConnect();
    H::dbQuery($pluginDb, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0.0')", $pluginId));
    H::dbClose($pluginDb);
    // No cache-clear needed: PluginConfig\PluginRegistry::bootActive()
    // always re-queries active plugins fresh on every request,
    // same as this file's own "logs a PHP warning..." test above already
    // established.

    try {
        $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId);
        $body = H::rawWebpage($page)->content();

        expect($body)
            ->toContain($marker);
        // picture_content.latte's own <img id="theMainImage" ...> wrapper
        // (built entirely from $current.selected_derivative/
        // $current.unique_derivatives, which defaultPictureContent() never
        // reaches once it short-circuits) is genuinely absent -- proving
        // the plugin's raw return value became ELEMENT_CONTENT verbatim,
        // not merely that the plugin ran at all.
        expect($body)
            ->not->toContain('id="theMainImage"');
        $page->assertNoJavaScriptErrors();
    } finally {
        $cleanupDb = pictureDbConnect();
        H::dbQuery($cleanupDb, sprintf("DELETE FROM plugins WHERE id = '%s'", $pluginId));
        H::dbClose($cleanupDb);
        pictureRemoveFixturePlugin($pluginDir);
    }
});

it('lets a plugin hide a display-info field via FilterPictureDisplayInfo', function (): void {
    // AdminTools_16.3.0's own set_prefilter('picture', 'admintools_remove_privacy')
    // hides the privacy_level control from picture.php's info panel when
    // its own quick-edit panel already shows an equivalent control --
    // ported here as a real filter-event dispatch (Picture\Event\
    // FilterPictureDisplayInfo, PictureController.php right after reading
    // CurrentConfig::$pictureInformations). 'visits' is the field targeted
    // here rather than 'privacy_level' itself: picture.latte's own
    // `{if $display_info['visits']}` block (unlike author/created_on) has
    // no companion `isset($INFO_...)` guard, so it renders unconditionally
    // whenever the flag is true -- a reliable, content-independent marker
    // for "did the filtered array actually reach the template," without
    // needing to fabricate a specific permission-level fixture.
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Display Info Filter Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Display Info Filter Photo');
    @unlink($image);

    $baselinePage = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId);
    $baselineBody = H::rawWebpage($baselinePage)->content();
    expect($baselineBody)
        ->toContain('id="Visits"');

    $pluginId = 'pwgtest-picture-filter-display-info';
    $pluginDir = dirname(__DIR__, 2) . '/plugins/' . $pluginId;

    pictureWriteFixturePlugin($pluginDir, <<<PHP
        \Piwigo\Tests\Support\EventDispatcherTestFactory::get()->addTypedHandler(
            \\Piwigo\\Picture\\Event\\FilterPictureDisplayInfo::class,
            static function (\\Piwigo\\Picture\\Event\\FilterPictureDisplayInfo \$event): \\Piwigo\\Picture\\Event\\FilterPictureDisplayInfo {
                if (\$event->imageId === {$imageId}) {
                    \$event->displayInfo['visits'] = false;
                }

                return \$event;
            }
        );
        PHP);

    $pluginDb = pictureDbConnect();
    H::dbQuery($pluginDb, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0.0')", $pluginId));
    H::dbClose($pluginDb);
    // No cache-clear needed: PluginConfig\PluginRegistry::bootActive()
    // always re-queries active plugins fresh on every request, same as
    // this file's other fixture-plugin tests above.

    try {
        $filteredPage = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId);
        $filteredBody = H::rawWebpage($filteredPage)->content();

        expect($filteredBody)
            ->not->toContain('id="Visits"');
        $filteredPage->assertNoJavaScriptErrors();
    } finally {
        $cleanupDb = pictureDbConnect();
        H::dbQuery($cleanupDb, sprintf("DELETE FROM plugins WHERE id = '%s'", $pluginId));
        H::dbClose($cleanupDb);
        pictureRemoveFixturePlugin($pluginDir);
    }
});

it('dispatches PicturePageRendered with the real requested image id', function (): void {
    // EXTRA_BODY_CONTENT (this test's original marker-delivery mechanism)
    // was deleted as dead template code (P44-B, zero real producers) --
    // captures the dispatched event's own imageId directly to a marker
    // file instead of smuggling it through rendered HTML, sidestepping
    // any dependency on a live template-injection point entirely.
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Picture Page Rendered Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Picture Page Rendered Photo');
    @unlink($image);

    $pluginId = 'pwgtest-picture-page-rendered';
    $pluginDir = dirname(__DIR__, 2) . '/plugins/' . $pluginId;
    // _data/tmp/, not sys_get_temp_dir(): Apache runs with systemd's
    // PrivateTmp=yes, so a file the plugin writes into /tmp from inside
    // the real web request is invisible to this CLI test process's own
    // /tmp -- _data/tmp/ is a real, shared, world-writable project
    // directory both processes actually see.
    $markerFile = dirname(__DIR__, 2) . '/_data/tmp/pwgtest-picture-page-rendered-' . uniqid() . '.txt';

    pictureWriteFixturePlugin($pluginDir, <<<PHP
        \Piwigo\Tests\Support\EventDispatcherTestFactory::get()->addTypedHandler(
            \\Piwigo\\Controller\\Event\\PicturePageRendered::class,
            static function (\\Piwigo\\Controller\\Event\\PicturePageRendered \$event) use (\$context): void {
                file_put_contents('{$markerFile}', (string) \$event->imageId);
            }
        );
        PHP);

    $pluginDb = pictureDbConnect();
    H::dbQuery($pluginDb, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0.0')", $pluginId));
    H::dbClose($pluginDb);

    try {
        $renderedPage = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId);

        expect(file_exists($markerFile))
            ->toBeTrue('PicturePageRendered handler never ran');
        expect(file_get_contents($markerFile))
            ->toBe((string) $imageId);
        $renderedPage->assertNoJavaScriptErrors();
    } finally {
        $cleanupDb = pictureDbConnect();
        H::dbQuery($cleanupDb, sprintf("DELETE FROM plugins WHERE id = '%s'", $pluginId));
        H::dbClose($cleanupDb);
        pictureRemoveFixturePlugin($pluginDir);
        @unlink($markerFile);
    }
});

it('renders the add-comment form with the fields a classic user needs and without the ones the server already knows', function (): void {
    // No fixture renders this block at all: picture-1.html is captured
    // anonymously and the fixture config leaves comments_forall false, so
    // `PictureCommentsResult::$commentAdd` is null there and every one of
    // the template's eleven reads off it is unexercised. The Integration
    // suite covers how the form is *built*; this covers that the template
    // still renders what it builds.
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/picture.php?/1/category/1');
    $body = H::rawWebpage($page)->content();

    $start = strpos($body, 'id="commentAdd"');
    expect($start)
        ->not->toBeFalse();
    $form = substr($body, (int) $start, 1200);

    expect($form)
        // formAction
        ->toContain('action="picture.php?/1/category/1"')
        // showWebsite is on by default, so the field is offered...
        ->and($form)
        ->toContain('name="website_url"')
        // ...while showAuthor and showEmail are both false for a classic
        // user with a registered address: the server already knows both,
        // so the inputs are omitted rather than pre-filled. An absent
        // field is exactly what a snapshot cannot assert.
        ->and($form)
        ->not->toContain('name="author"')
        ->and($form)
        ->not->toContain('name="email"')
        // the ephemeral key, whose three parts EphemeralKeyService builds
        // as <timestamp>:<validAfterSeconds>:<hash>
        ->and($form)
        ->toMatch('/name="key" value="[0-9.]+:3:[0-9a-f]{64}"/');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'picture.php add-comment form');
});

it('offers the photo-sizes switcher when a photo has more than one distinct derivative', function (): void {
    // Dead markup until this was fixed: `selected_derivative`/
    // `unique_derivatives` used to reach picture.tpl by being appended
    // into the shared ambient `current` template variable, and when P40
    // split that into PictureView::$navCurrent and
    // PictureContentView::$current only the second half kept receiving
    // them. `$navCurrent['unique_derivatives']` was never set by anything
    // after that, so the isset() guard on this block was permanently
    // false and the switcher never rendered on any picture page.
    //
    // No fixture can catch it either way: every fixture photo is 200x150,
    // below every configured derivative width, so ImageStdParams resolves
    // them all to the original and the URL dedupe leaves exactly one
    // entry. Hence the deliberately large upload here.
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Photo Sizes Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage('sizes', 1600, 1200);
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Photo Sizes Photo');
    @unlink($image);

    $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId);
    $body = H::rawWebpage($page)->content();

    expect($body)
        ->toContain('id="derivativeSwitchLink"')
        ->and($body)
        ->toContain('id="derivativeSwitchBox"')
        // More than one size on offer is the whole precondition, and each
        // carries the type its link switches to.
        ->and($body)
        ->toContain('data-derivative-type-save="2small"')
        ->and($body)
        ->toContain('data-derivative-type-save="xsmall"');

    // Exactly one size is marked current: every other entry carries
    // u-invisible on its checkmark. This is the half that reads
    // $selectedSizeType, and it is why that value is the *resolved*
    // getType() rather than the key `unique` is stored under.
    $marks = preg_match_all('/<span class="switchCheck( u-invisible)?"/', $body, $matches);
    expect($marks)
        ->toBeGreaterThan(1);
    expect(count(array_filter($matches[1], static fn (string $m): bool => $m === '')))
        ->toBe(1);

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'picture.php photo-sizes switcher');
});
