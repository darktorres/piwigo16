<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\PictureController (picture.php) -- the single-photo
 * detail page. Covers the hit-counter increment (and its "don't
 * double-count a same-picture reload" branch), the favorites toggle (a
 * real add_to_favorites/remove_from_favorites action round trip verified
 * against piwigo_favorites), the invalid-image_id 404 branch, and the
 * admin comment-moderation actions this controller's own switch handles
 * (delete_comment/validate_comment) -- distinct from CommentsController's
 * own moderation actions (comments.php), covered separately.
 *
 * Every real navigation below uses the pretty-URL form
 * `picture.php?/{imageId}/category/{albumId}` (the exact form
 * pwg.images.addSimple's own WS response returns as the new photo's URL),
 * not a bare `picture.php?image_id={imageId}`. Confirmed live (reproduced
 * directly with curl, independent of this file) that the bare form makes
 * SectionPopulator default to the flat "categories" section with no
 * specific category selected -- whether a freshly uploaded photo's id
 * lands inside that default (unfiltered, sorted, paginated) items list is
 * NOT deterministic (depends on how many other images already exist
 * site-wide, their sort order, and the default page size), so a bare
 * image_id can 404 or hit an unrelated accessDenied() branch depending on
 * ambient DB state -- exactly the kind of ambient-state fragility this
 * suite otherwise avoids. Scoping every request to the real album makes
 * PictureController's own `$page_category !== null` branch deterministic.
 */

function pictureDbConnect(): mysqli
{
    return new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
}

function pictureDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

function pictureHitCount(int $imageId): int
{
    $db = pictureDbConnect();
    $result = $db->query(sprintf('SELECT hit FROM %simages WHERE id = %d', pictureDbPrefix(), $imageId));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

    return is_array($row) && isset($row['hit']) ? (int) $row['hit'] : -1;
}

function pictureFavoriteExists(int $imageId, int $userId): bool
{
    $db = pictureDbConnect();
    $result = $db->query(sprintf(
        'SELECT COUNT(*) AS c FROM %sfavorites WHERE image_id = %d AND user_id = %d',
        pictureDbPrefix(),
        $imageId,
        $userId
    ));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

    return is_array($row) && (int) $row['c'] > 0;
}

/** Inserts a real comment row directly (matches RegenerateFixtureTest's own direct-insert shape) and returns its id. */
function pictureInsertComment(int $imageId, string $author, string $content, bool $validated, ?int $authorId = null): int
{
    $db = pictureDbConnect();
    $prefix = pictureDbPrefix();
    $db->query(sprintf(
        "INSERT INTO %scomments (image_id, date, author, anonymous_id, author_id, content, validated, validation_date) VALUES (%d, NOW(), '%s', '127.0.0.9', %s, '%s', %d, %s)",
        $prefix,
        $imageId,
        $db->real_escape_string($author),
        $authorId === null ? 'NULL' : (string) $authorId,
        $db->real_escape_string($content),
        $validated ? 1 : 0,
        $validated ? 'NOW()' : 'NULL'
    ));
    $id = (int) $db->insert_id;
    $db->close();

    return $id;
}

/** @return array{validated: int}|null */
function pictureCommentRow(int $commentId): ?array
{
    $db = pictureDbConnect();
    $result = $db->query(sprintf('SELECT validated FROM %scomments WHERE id = %d', pictureDbPrefix(), $commentId));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

    return is_array($row) ? ['validated' => (int) $row['validated']] : null;
}

it('increments the hit counter on first view, then not on an immediate same-picture reload', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Picture Test Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    // Piwigo\Admin\Upload\UploadService::addUploadedFile() de-duplicates by
    // md5sum whenever CurrentConfig::uploadDetectDuplicate() is enabled
    // (confirmed live: this fixture's own piwigo_config ships
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
    // (uniqid()'s own encoding). Reproduced live: two DIFFERENT
    // 'Hit Count Photo ' . uniqid() labels, run back-to-back, produced
    // byte-IDENTICAL JPEGs (same md5), while two bare uniqid() labels
    // (short enough to render in full) produced genuinely different
    // ones -- confirmed independently of Pest via a standalone php -r
    // reproduction before writing this fix. The PIXEL label (must stay
    // short enough to render in full) and the DB `name` field (the
    // descriptive, human-readable text picture.tpl actually displays,
    // asserted below) are deliberately decoupled here for exactly this
    // reason.
    $pixelLabel = uniqid();
    $displayName = 'Hit Count Photo';
    $image = H::makeTestImage($pixelLabel);
    $imageId = H::uploadPhotoViaApi($image, $albumId, $displayName);
    @unlink($image);

    expect(pictureHitCount($imageId))->toBe(0);

    $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId);
    $page->assertSee($displayName);
    expect(pictureHitCount($imageId))->toBe(1);

    // SessionService's own 'referer_image_id' session var: an immediate
    // reload of the SAME picture (same session) must not double-count.
    $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId);
    expect(pictureHitCount($imageId))->toBe(1);
});

it('adds and removes a photo from favorites via the picture.php action links, verified in piwigo_favorites', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Picture Test Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
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

    expect(pictureFavoriteExists($imageId, 1))->toBeFalse();

    $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId . '&action=add_to_favorites');
    expect(pictureFavoriteExists($imageId, 1))->toBeTrue();

    $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId . '&action=remove_from_favorites');
    expect(pictureFavoriteExists($imageId, 1))->toBeFalse();
});

it('404s with "Page not found" for a nonexistent image_id', function (): void {
    expect(H::httpStatus('picture.php?image_id=999999999'))->toBe(404);
    expect(H::httpBody('picture.php?image_id=999999999'))->toContain('Page not found');
});

it('lets an admin delete and validate comments directly from picture.php\'s own actions', function (): void {
    $page = H::loginAsAdmin($this);
    $pwgToken = H::pwgToken($page);

    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Picture Test Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage('Comment Moderation Photo');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Comment Moderation Photo');
    @unlink($image);

    // author_id=3 (regular_user, a real registered account) -- see this
    // test file's own bug-confirmation test below for why a NULL author_id
    // (an anonymous/guest-authored comment) can't be used here.
    $toDeleteId = pictureInsertComment($imageId, 'delete-me-' . uniqid(), 'This one gets deleted.', true, 3);
    $toValidateId = pictureInsertComment($imageId, 'validate-me-' . uniqid(), 'This one gets validated.', false, 3);

    $beforeRow = pictureCommentRow($toValidateId);
    if ($beforeRow === null) {
        throw new \RuntimeException('expected a real comment row for id ' . $toValidateId);
    }
    expect($beforeRow['validated'])->toBe(0);

    $page = H::navigateOk(
        $page,
        '/picture.php?/' . $imageId . '/category/' . $albumId . '&action=delete_comment&comment_to_delete=' . $toDeleteId . '&pwg_token=' . $pwgToken
    );
    expect(pictureCommentRow($toDeleteId))->toBeNull();

    $page = H::navigateOk(
        $page,
        '/picture.php?/' . $imageId . '/category/' . $albumId . '&action=validate_comment&comment_to_validate=' . $toValidateId . '&pwg_token=' . $pwgToken
    );
    $row = pictureCommentRow($toValidateId);
    if ($row === null) {
        throw new \RuntimeException('expected a real comment row for id ' . $toValidateId);
    }
    expect($row['validated'])->toBe(1);
});

it('CONFIRMED BUG: delete_comment 500s instead of deleting for an anonymous (NULL author_id) comment', function (): void {
    // CommentService::getCommentAuthorId() returns `false` (its
    // "not found"/"die_on_error" sentinel type, `int|false`) whenever the
    // comment's own `author_id` column is NULL -- true for any real guest/
    // anonymous-authored comment, not an edge case. PictureController's own
    // `assert($author_id !== false)` right after that call is meant to rule
    // this out, but zend.assertions=-1 in this environment (confirmed
    // elsewhere in this codebase) makes assert() a genuine runtime no-op --
    // so `$author_id` stays `false` and is passed straight into
    // `AccessControl::canManageComment(string $action, int|string
    // $commentAuthorId)`, whose 2nd parameter's declared type does NOT
    // include bool. Under this project's `declare(strict_types=1)`, that is
    // a real, uncaught TypeError, not a soft coercion -- reproduced here
    // via a raw authenticated curl request (independent of Playwright)
    // before writing this assertion. Any real admin who tries to
    // delete/validate/edit a genuine anonymous visitor's comment from
    // picture.php hits this every time.
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
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Piwigo-Env: test']);
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
    $post($baseUrl . '/identification.php');
    $post($baseUrl . '/identification.php', [
        'username' => H::ADMIN_USER,
        'password' => H::ADMIN_PASS,
        'login' => 'Login',
    ]);

    $statusResult = $post($baseUrl . '/ws.php?format=json', ['method' => 'pwg.session.getStatus']);
    $decodedStatus = json_decode($statusResult['body'], true);
    $statusResultData = is_array($decodedStatus) ? ($decodedStatus['result'] ?? null) : null;
    $pwgTokenRaw = is_array($statusResultData) ? ($statusResultData['pwg_token'] ?? null) : null;
    $pwgToken = is_string($pwgTokenRaw) || is_int($pwgTokenRaw) ? (string) $pwgTokenRaw : '';
    expect($pwgToken)->not->toBe('');

    $album = $post($baseUrl . '/ws.php?format=json', ['method' => 'pwg.categories.add', 'name' => 'Picture Bug Test Album ' . uniqid()]);
    $decodedAlbum = json_decode($album['body'], true);
    $albumResultData = is_array($decodedAlbum) ? ($decodedAlbum['result'] ?? null) : null;
    $albumIdRaw = is_array($albumResultData) ? ($albumResultData['id'] ?? null) : null;
    $albumId = is_numeric($albumIdRaw) ? (int) $albumIdRaw : 0;
    expect($albumId)->toBeGreaterThan(0);

    $image = H::makeTestImage('Anon Comment Bug Photo');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Anon Comment Bug Photo');
    @unlink($image);

    // authorId: null -> a real anonymous/guest comment, matching what any
    // real, un-registered visitor leaves.
    $anonCommentId = pictureInsertComment($imageId, 'guest', 'An anonymous visitor left this.', true, null);

    $result = $post($baseUrl . '/picture.php?/' . $imageId . '/category/' . $albumId . '&action=delete_comment&comment_to_delete=' . $anonCommentId . '&pwg_token=' . $pwgToken);

    @unlink($cookieJar);

    expect($result['status'])->toBe(500);
    // The comment was never actually deleted -- the TypeError fires before
    // deleteComment() is ever reached.
    expect(pictureCommentRow($anonCommentId))->not->toBeNull();
});
