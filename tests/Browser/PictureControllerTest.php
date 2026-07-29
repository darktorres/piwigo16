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

function pictureCategoryRepresentativeId(int $categoryId): ?int
{
    $db = pictureDbConnect();
    $result = $db->query(sprintf('SELECT representative_picture_id FROM %scategories WHERE id = %d', pictureDbPrefix(), $categoryId));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

    return is_array($row) && $row['representative_picture_id'] !== null ? (int) $row['representative_picture_id'] : null;
}

function pictureCaddieExists(int $imageId, int $userId): bool
{
    $db = pictureDbConnect();
    $result = $db->query(sprintf(
        'SELECT COUNT(*) AS c FROM %scaddie WHERE element_id = %d AND user_id = %d',
        pictureDbPrefix(),
        $imageId,
        $userId
    ));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

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
    $result = $db->query(sprintf(
        'SELECT rate FROM %srate WHERE element_id = %d AND user_id = %d',
        pictureDbPrefix(),
        $imageId,
        $userId
    ));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

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

    // author_id=3 (regular_user, a real registered account); the
    // anonymous (NULL author_id) case is covered separately below.
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

it('delete_comment succeeds for an anonymous (NULL author_id) comment', function (): void {
    // Regression test for a fixed bug: CommentService::getCommentAuthorId()
    // used to collapse "comment not found" and "comment has NULL author_id"
    // (a real, common state for any guest/anonymous-authored comment) down
    // to the same `false` sentinel. That `false` then flowed into
    // AccessControl::canManageComment(string $action, int|string
    // $commentAuthorId), whose 2nd parameter's declared type did NOT
    // include bool, and under this project's `declare(strict_types=1)`
    // triggered a real, uncaught TypeError -- reproduced via a raw
    // authenticated curl request (independent of Playwright) before the
    // fix. getCommentAuthorId() now returns `null` for this case (see
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

    expect($result['status'])->toBe(302);
    expect(pictureCommentRow($anonCommentId))->toBeNull();
});

it("edits a comment's own content via the edit_comment action, validating it as admin", function (): void {
    // Distinct from delete_comment/validate_comment above: edit_comment
    // is the "change a comment's own text" flow
    // (CommentService::updateComment()), never exercised before. Its
    // ephemeral post key is only ever rendered into the page for the ONE
    // comment currently being edited (comment_list.tpl's own
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
    // spec's opaque 0 for a redirect, never the real 302 (confirmed
    // elsewhere this session), so FOLLOWLOCATION is required to observe
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

        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    };

    $baseUrl = H::baseUrl();
    $curl($baseUrl . '/identification.php');
    $curl($baseUrl . '/identification.php', [
        'username' => H::ADMIN_USER,
        'password' => H::ADMIN_PASS,
        'login' => 'Login',
    ]);

    $statusResult = $curl($baseUrl . '/ws.php?format=json', ['method' => 'pwg.session.getStatus']);
    $decodedStatus = json_decode($statusResult['body'], true);
    $statusResultData = is_array($decodedStatus) ? ($decodedStatus['result'] ?? null) : null;
    $pwgTokenRaw = is_array($statusResultData) ? ($statusResultData['pwg_token'] ?? null) : null;
    $pwgToken = is_string($pwgTokenRaw) || is_int($pwgTokenRaw) ? (string) $pwgTokenRaw : '';
    expect($pwgToken)->not->toBe('');

    $album = $curl($baseUrl . '/ws.php?format=json', ['method' => 'pwg.categories.add', 'name' => 'Picture Edit Comment Album ' . uniqid()]);
    $decodedAlbum = json_decode($album['body'], true);
    $albumResultData = is_array($decodedAlbum) ? ($decodedAlbum['result'] ?? null) : null;
    $albumIdRaw = is_array($albumResultData) ? ($albumResultData['id'] ?? null) : null;
    $albumId = is_numeric($albumIdRaw) ? (int) $albumIdRaw : 0;
    expect($albumId)->toBeGreaterThan(0);

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
    // submitted this fast" anti-bot check, confirmed live: verify()
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
    $row = H::fetchAssocOrFail($db, sprintf('SELECT content, validated FROM %scomments WHERE id = %d', pictureDbPrefix(), $commentId));
    $db->close();
    expect($row['content'])->toBe($newContent);
    // Admin editing any comment always takes the 'validate' branch
    // (CommentService::updateComment(): `!commentsValidation() ||
    // isAdmin()`), regardless of the fixture's own comments_validation
    // setting.
    expect((int) $row['validated'])->toBe(1);
});

it('navigates between previous/next/first/last items across a 3-photo album, ordered by title', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Nav Test Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];

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
    expect($middleBody)->toContain('Previous :');
    expect($middleBody)->toContain('Nav Photo A ' . $suffix);
    expect($middleBody)->toContain('Next :');
    expect($middleBody)->toContain('Nav Photo C ' . $suffix);

    $firstBody = pictureGetWithCookies($cookieJar, '/picture.php?/' . $idA . '/category/' . $albumId);
    expect($firstBody)->not->toContain('Previous :');
    expect($firstBody)->toContain('Next :');
    expect($firstBody)->toContain('Nav Photo B ' . $suffix);

    $lastBody = pictureGetWithCookies($cookieJar, '/picture.php?/' . $idC . '/category/' . $albumId);
    expect($lastBody)->toContain('Previous :');
    expect($lastBody)->toContain('Nav Photo B ' . $suffix);
    expect($lastBody)->not->toContain('Next :');

    @unlink($cookieJar);
});

it('sets a photo as the album representative via the set_as_representative action', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Representative Test Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    // A freshly created album auto-assigns its first-ever uploaded photo
    // as representative (confirmed live) -- upload a second photo and
    // explicitly re-target it, so this test proves the action itself
    // changes the representative rather than observing an already-set
    // default.
    $firstImage = H::makeTestImage(uniqid());
    $firstImageId = H::uploadPhotoViaApi($firstImage, $albumId, 'First Photo');
    @unlink($firstImage);
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Representative Photo');
    @unlink($image);

    expect(pictureCategoryRepresentativeId($albumId))->toBe($firstImageId);

    $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId . '&action=set_as_representative');
    expect(pictureCategoryRepresentativeId($albumId))->toBe($imageId);
});

it('adds a photo to the caddie via the add_to_caddie action', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Caddie Test Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Caddie Photo');
    @unlink($image);

    expect(pictureCaddieExists($imageId, 1))->toBeFalse();

    $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId . '&action=add_to_caddie');
    expect(pictureCaddieExists($imageId, 1))->toBeTrue();
});

it('rates a photo via the rate action', function (): void {
    // RateService::rate() silently no-ops (returns false, never inserts a
    // row) unless CurrentConfig::rateEnabled() is true -- and this
    // fixture's own `rate` config param (not `rate_enabled`: the DB
    // param/property mapping is `'rate' => 'rateEnabled'`, confirmed live)
    // is explicitly seeded 'false', so rating is genuinely disabled by
    // default in this environment.
    $snapshot = H::snapshotConfig(['rate']);
    H::setConfigValue('rate', 'true');

    try {
        $page = H::loginAsAdmin($this);
        $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Rate Test Album ' . uniqid()]);
        $albumResult = $album['result'] ?? null;
        if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
            throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $albumResult['id'];
        $image = H::makeTestImage(uniqid());
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'Rate Photo');
        @unlink($image);

        expect(pictureRateValue($imageId, 1))->toBeNull();

        // The action's own success path ends in RedirectServiceInterface::
        // redirect() -- adminPost() uses fetch(..., {redirect:'manual'}), so a
        // real 30x comes back as an opaque status 0, not the real code (see
        // this session's own feedback_fetch_manual_redirect_status_zero memory).
        $result = H::adminPost($page, '/picture.php?/' . $imageId . '/category/' . $albumId . '&action=rate', [
            'rate' => '4',
        ]);
        expect($result['status'])->toBe(0);
        expect(pictureRateValue($imageId, 1))->toBe(4);
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

        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    };

    $baseUrl = H::baseUrl();
    $curl($baseUrl . '/identification.php');
    $curl($baseUrl . '/identification.php', [
        'username' => H::ADMIN_USER,
        'password' => H::ADMIN_PASS,
        'login' => 'Login',
    ]);

    $statusResult = $curl($baseUrl . '/ws.php?format=json', ['method' => 'pwg.session.getStatus']);
    $decodedStatus = json_decode($statusResult['body'], true);
    $statusResultData = is_array($decodedStatus) ? ($decodedStatus['result'] ?? null) : null;
    $pwgTokenRaw = is_array($statusResultData) ? ($statusResultData['pwg_token'] ?? null) : null;
    $pwgToken = is_string($pwgTokenRaw) || is_int($pwgTokenRaw) ? (string) $pwgTokenRaw : '';
    expect($pwgToken)->not->toBe('');

    $album = $curl($baseUrl . '/ws.php?format=json', ['method' => 'pwg.categories.add', 'name' => 'Picture Reject Comment Album ' . uniqid()]);
    $decodedAlbum = json_decode($album['body'], true);
    $albumResultData = is_array($decodedAlbum) ? ($decodedAlbum['result'] ?? null) : null;
    $albumIdRaw = is_array($albumResultData) ? ($albumResultData['id'] ?? null) : null;
    $albumId = is_numeric($albumIdRaw) ? (int) $albumIdRaw : 0;
    expect($albumId)->toBeGreaterThan(0);

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
    // $commentAction = 'reject' branch (confirmed live): a 200 response
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
    // for that literal string misses -- confirmed live, and the same
    // mechanism this session's own PasswordController fix (a873f5ca7d)
    // relies on for its own page_errors flash. Since 'reject' never
    // redirects, this same response (not a follow-up one) is what
    // flushPageMessages() renders it into.
    expect($postResult['body'])->toContain('Your comment has NOT been registered because it did not pass the validation rules');

    $db = pictureDbConnect();
    $row = H::fetchAssocOrFail($db, sprintf('SELECT content FROM %scomments WHERE id = %d', pictureDbPrefix(), $commentId));
    $db->close();
    expect($row['content'])->toBe('Original content.');
});

it('toggles the show_metadata session flag on repeated ?metadata visits without erroring', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Picture Metadata Toggle Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Metadata Toggle Photo');
    @unlink($image);

    // First visit: getSessionVar('show_metadata') is null -> sets it to 1.
    $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId . '&metadata');
    $page->assertNoJavaScriptErrors();
    // Second visit, same session: no longer null -> unsets it. Neither
    // branch has any template-visible effect of its own (confirmed live:
    // $url_metadata is a plain "current URL + metadata param" link, not
    // conditioned on the session var's value) -- exercising both real
    // branches without erroring is the correct assertion here.
    $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId . '&metadata');
    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'picture.php show_metadata toggle');
});

it('renders a related tag link for a photo with a real assigned tag', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Picture Related Tags Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Related Tags Photo');
    @unlink($image);

    $tagName = 'Related Tag ' . uniqid();
    $tagResult = H::wsCall($page, 'pwg.tags.add', ['name' => $tagName]);
    $tagData = $tagResult['result'] ?? null;
    $tagId = is_array($tagData) ? ($tagData['id'] ?? null) : null;
    if (! is_numeric($tagId)) {
        throw new RuntimeException('pwg.tags.add did not return a numeric id: ' . var_export($tagResult, true));
    }

    $updateResult = H::wsCall($page, 'pwg.images.setInfo', [
        'image_id' => (string) $imageId,
        'tag_ids' => (string) $tagId,
    ]);
    expect($updateResult['stat'] ?? null)->toBe('ok');

    // picture.tpl's own {if ($display_info.tags and isset($related_tags))}
    // gate needs the fixture's real picture_informations config to have
    // tags=true (confirmed live) -- true by default in this fixture, not
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

function pictureSetImageDateAvailable(int $imageId, string $mysqlDateTime): void
{
    $db = pictureDbConnect();
    $db->query(sprintf(
        "UPDATE %simages SET date_available = '%s' WHERE id = %d",
        pictureDbPrefix(),
        $db->real_escape_string($mysqlDateTime),
        $imageId
    ));
    $db->close();
}

/** Directly links an image to an additional category -- for the multi-category breadcrumb test below, which needs a photo genuinely associated with 2+ real albums, not achievable through pwg.images.addSimple's own single-category upload. */
function pictureAddImageToCategory(int $imageId, int $categoryId): void
{
    $db = pictureDbConnect();
    $db->query(sprintf(
        'INSERT INTO %simage_category (image_id, category_id) VALUES (%d, %d)',
        pictureDbPrefix(),
        $imageId,
        $categoryId
    ));
    $db->close();
}

/** Directly strips every image_category row for an image -- for the fetchOne()===false access-denied branch below, which needs a real, otherwise-normal image genuinely orphaned from every category (not reachable by simply viewing it through the wrong album, which the row_level/filtered checks intercept first). */
function pictureRemoveImageFromAllCategories(int $imageId): void
{
    $db = pictureDbConnect();
    $db->query(sprintf('DELETE FROM %simage_category WHERE image_id = %d', pictureDbPrefix(), $imageId));
    $db->close();
}

/** Directly inserts a piwigo_image_format row -- for the format-list building test below (download-URL fallback/Lang-key label lookup/filesize MB-formatting), not reachable through any WS method that lets a test control the stored filesize precisely. */
function pictureInsertImageFormat(int $imageId, string $ext, int $filesizeKb): void
{
    $db = pictureDbConnect();
    $db->query(sprintf(
        "INSERT INTO %simage_format (image_id, ext, filesize) VALUES (%d, '%s', %d)",
        pictureDbPrefix(),
        $imageId,
        $db->real_escape_string($ext),
        $filesizeKb
    ));
    $db->close();
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
 * DB-backed `sessions` table (Piwigo\Session\PwgSession's own save
 * handler), keyed the same way that handler stores it: a literal '7F00'
 * prefix in front of the session id carried by the `pwg_id` cookie
 * (confirmed live -- the same fixed prefix appears for every session id
 * regardless of client, not an IP-derived value despite the hex look).
 * Session data uses PHP's own default `session.serialize_handler` format,
 * and every real session var this app writes is itself namespaced with a
 * `pwg_` prefix (SessionService's own convention) -- a plain regex avoids
 * depending on session_decode()'s own ambient ini state from this
 * separate CLI process.
 */
function pictureSessionDerivType(string $pwgIdCookieValue): ?string
{
    $db = pictureDbConnect();
    $result = $db->query(sprintf(
        "SELECT data FROM %ssessions WHERE id = '7F00%s'",
        pictureDbPrefix(),
        $db->real_escape_string($pwgIdCookieValue)
    ));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

    $data = is_array($row) && is_string($row['data'] ?? null) ? $row['data'] : '';
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

    $ownAlbum = $curl($baseUrl . '/ws.php?format=json', ['method' => 'pwg.categories.add', 'name' => 'Level Test Own Album ' . uniqid()]);
    $ownAlbumData = json_decode($ownAlbum['body'], true);
    $ownAlbumResult = is_array($ownAlbumData) ? ($ownAlbumData['result'] ?? null) : null;
    $ownAlbumIdRaw = is_array($ownAlbumResult) ? ($ownAlbumResult['id'] ?? null) : null;
    $ownAlbumId = is_numeric($ownAlbumIdRaw) ? (int) $ownAlbumIdRaw : 0;
    expect($ownAlbumId)->toBeGreaterThan(0);

    $otherAlbum = $curl($baseUrl . '/ws.php?format=json', ['method' => 'pwg.categories.add', 'name' => 'Level Test Other Album ' . uniqid()]);
    $otherAlbumData = json_decode($otherAlbum['body'], true);
    $otherAlbumResult = is_array($otherAlbumData) ? ($otherAlbumData['result'] ?? null) : null;
    $otherAlbumIdRaw = is_array($otherAlbumResult) ? ($otherAlbumResult['id'] ?? null) : null;
    $otherAlbumId = is_numeric($otherAlbumIdRaw) ? (int) $otherAlbumIdRaw : 0;
    expect($otherAlbumId)->toBeGreaterThan(0);

    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $ownAlbumId, 'Level Test Photo');
    @unlink($image);

    // level=8 is above any non-admin user's default level.
    $curl($baseUrl . '/ws.php?format=json', ['method' => 'pwg.images.setPrivacyLevel', 'image_id' => (string) $imageId, 'level' => '8']);

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

    $ownAlbum = $curl($baseUrl . '/ws.php?format=json', ['method' => 'pwg.categories.add', 'name' => 'Filtered Test Own Album ' . uniqid()]);
    $ownAlbumData = json_decode($ownAlbum['body'], true);
    $ownAlbumResult = is_array($ownAlbumData) ? ($ownAlbumData['result'] ?? null) : null;
    $ownAlbumIdRaw = is_array($ownAlbumResult) ? ($ownAlbumResult['id'] ?? null) : null;
    $ownAlbumId = is_numeric($ownAlbumIdRaw) ? (int) $ownAlbumIdRaw : 0;
    expect($ownAlbumId)->toBeGreaterThan(0);

    $otherAlbum = $curl($baseUrl . '/ws.php?format=json', ['method' => 'pwg.categories.add', 'name' => 'Filtered Test Other Album ' . uniqid()]);
    $otherAlbumData = json_decode($otherAlbum['body'], true);
    $otherAlbumResult = is_array($otherAlbumData) ? ($otherAlbumData['result'] ?? null) : null;
    $otherAlbumIdRaw = is_array($otherAlbumResult) ? ($otherAlbumResult['id'] ?? null) : null;
    $otherAlbumId = is_numeric($otherAlbumIdRaw) ? (int) $otherAlbumIdRaw : 0;
    expect($otherAlbumId)->toBeGreaterThan(0);

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
    pictureSetImageDateAvailable($oldImageId, date('Y-m-d H:i:s', strtotime('-30 days')));

    // Activates FilterService::initializeFromRequest()'s session-persisted
    // recent-content filter (start-recent-1 -> a real 1-day window),
    // confirmed live via source read: CurrentConfig::filterPages()'s
    // 'default' entry has used=true, so this runs on every subsequent
    // request in this same cookie-jar session, including picture.php.
    $curl($baseUrl . '/index.php?filter=start-recent-1');

    $result = $curl($baseUrl . '/picture.php?/' . $oldImageId . '/category/' . $otherAlbumId);

    @unlink($adminSession['cookieJar']);

    expect($result['status'])->toBe(404);
    expect($result['body'])->toContain('The requested image is filtered');
});

it('redirects to the section listing (not back to the picture) when removing a favorite from within the favorites section', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Favorites Up Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Favorites Up Photo');
    @unlink($image);

    H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId . '&action=add_to_favorites');
    expect(pictureFavoriteExists($imageId, 1))->toBeTrue();

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

    expect($status)->toBe(302);
    expect(is_string($location) ? $location : '')->not->toContain('picture.php');
    expect(pictureFavoriteExists($imageId, 1))->toBeFalse();
});

it('does not increment the hit counter for a Firefox prefetch request (X-Moz: prefetch)', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Prefetch Test Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Prefetch Photo');
    @unlink($image);

    expect(pictureHitCount($imageId))->toBe(0);

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

    expect(pictureHitCount($imageId))->toBe(0);
});

it('remembers a picture_deriv cookie choice in the session across a follow-up request', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Deriv Cookie Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
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
    // CURLOPT_COOKIEFILE -- confirmed live via CURLOPT_VERBOSE that doing
    // the latter sends TWO separate `Cookie:` header lines on the wire
    // (one from the jar's real pwg_id, one from the manual header), which
    // Apache/PHP only honors one of (whichever it picks -- confirmed by a
    // brand-new pwg_id being issued back, meaning it silently dropped the
    // real session cookie), losing the logged-in admin session entirely.
    // Writing both cookies into the same jar file lets curl's own loader
    // merge them into one real `Cookie: picture_deriv=large; pwg_id=...`
    // header, which is what a real browser sending both cookies would
    // produce.
    $jarContents = file_get_contents($session['cookieJar']);
    if ($jarContents === false) {
        throw new RuntimeException('failed to read cookie jar: ' . $session['cookieJar']);
    }
    $baseUrlParts = parse_url($baseUrl);
    $cookieHost = is_string($baseUrlParts['host'] ?? null) ? $baseUrlParts['host'] : '127.0.0.1';
    $cookiePath = is_string($baseUrlParts['path'] ?? null) && $baseUrlParts['path'] !== '' ? $baseUrlParts['path'] : '/';
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
    expect($firstStatus)->toBe(200);
    expect(is_string($firstBody) ? $firstBody : '')->not->toContain('Fatal error');

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
    $page = H::loginAsAdmin($this);
    $albumName = 'Related Cats Fast Path Album ' . uniqid();
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => $albumName]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
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
    $page = H::loginAsAdmin($this);
    $albumAName = 'Multi Cat Album A ' . uniqid();
    $albumA = H::wsCall($page, 'pwg.categories.add', ['name' => $albumAName]);
    $albumAResult = $albumA['result'] ?? null;
    if (! is_array($albumAResult) || ! is_numeric($albumAResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($albumA, true));
    }
    $albumAId = (int) $albumAResult['id'];

    $albumBName = 'Multi Cat Album B ' . uniqid();
    $albumB = H::wsCall($page, 'pwg.categories.add', ['name' => $albumBName]);
    $albumBResult = $albumB['result'] ?? null;
    if (! is_array($albumBResult) || ! is_numeric($albumBResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($albumB, true));
    }
    $albumBId = (int) $albumBResult['id'];

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
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Picture Slideshow Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $suffix = uniqid();
    $imageA = H::makeTestImage($suffix . 'a');
    $idA = H::uploadPhotoViaApi($imageA, $albumId, 'Slideshow Photo A ' . $suffix);
    @unlink($imageA);
    $imageB = H::makeTestImage($suffix . 'b');
    H::uploadPhotoViaApi($imageB, $albumId, 'Slideshow Photo B ' . $suffix);
    @unlink($imageB);

    // light_slideshow defaults true (CurrentConfig::$lightSlideshow),
    // slideshow_period/min/max/step default 4/1/10/1 and slideshow_repeat
    // defaults true -- confirmed live, none overridden by this fixture --
    // so viewing photo A (which has a real next item, B) with slideshow=1
    // exercises the play=true auto-advance branch, both period-step
    // links (4-1=3 and 4+1=5 both stay within [1,10]), and the
    // repeat=true branch, all via slideshow.tpl + picture_nav_buttons.tpl.
    $page = H::navigateOk($page, '/picture.php?/' . $idA . '/category/' . $albumId . '&slideshow=1');

    $page->assertSee('stop the slideshow');
    // picture_nav_buttons.tpl's own control labels (pwg-button-text spans)
    // are icon-only, CSS-hidden text -- confirmed live via screenshot, so
    // assertSee() (visible text only) never finds them; a raw-content
    // check is the right tool, same precedent as BatchManagerUnitPageRenderer
    // Test's own title-attribute case. The loaded en_UK catalog also
    // rephrases these from their literal PHP source msgids (e.g. "Reduce
    // diaporama speed" -> "Reduce slideshow speed") -- confirmed live.
    $body = H::rawWebpage($page)->content();
    expect($body)->toContain('Reduce slideshow speed');
    expect($body)->toContain('Increase slideshow speed');
    expect($body)->toContain('Pause slideshow');
    expect($body)->toContain('Do not repeat slideshow');
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
    // with no category -- confirmed live via source read
    // (SectionPopulator.php's own "access a picture only by id, file or
    // id-file without given section" comment).
    $adminSession = pictureCurlLoginSession(H::ADMIN_USER, H::ADMIN_PASS);
    $curl = $adminSession['curl'];
    $baseUrl = $adminSession['baseUrl'];

    $album = $curl($baseUrl . '/ws.php?format=json', ['method' => 'pwg.categories.add', 'name' => 'Flat View Denied Album ' . uniqid()]);
    $albumData = json_decode($album['body'], true);
    $albumResult = is_array($albumData) ? ($albumData['result'] ?? null) : null;
    $albumIdRaw = is_array($albumResult) ? ($albumResult['id'] ?? null) : null;
    $albumId = is_numeric($albumIdRaw) ? (int) $albumIdRaw : 0;
    expect($albumId)->toBeGreaterThan(0);

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
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Orphaned Image Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
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
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Best Rated Fallback Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Best Rated Fallback Photo');
    @unlink($image);

    // No rate config/data manipulation needed: SectionPopulator's own
    // best_rated query (`ORDER BY rating_score DESC`) only ever returns
    // images with at least one real piwigo_rate row -- a brand-new,
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

    $album = $curl($baseUrl . '/ws.php?format=json', ['method' => 'pwg.categories.add', 'name' => 'Redirect Section Album ' . uniqid()]);
    $albumData = json_decode($album['body'], true);
    $albumResult = is_array($albumData) ? ($albumData['result'] ?? null) : null;
    $albumIdRaw = is_array($albumResult) ? ($albumResult['id'] ?? null) : null;
    $albumId = is_numeric($albumIdRaw) ? (int) $albumIdRaw : 0;
    expect($albumId)->toBeGreaterThan(0);

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

        return ['status' => $status, 'location' => is_string($location) ? $location : ''];
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
        $album = $adminSession['curl']($baseUrl . '/ws.php?format=json', ['method' => 'pwg.categories.add', 'name' => 'Moderate Flash Album ' . uniqid()]);
        $albumData = json_decode($album['body'], true);
        $albumResult = is_array($albumData) ? ($albumData['result'] ?? null) : null;
        $albumIdRaw = is_array($albumResult) ? ($albumResult['id'] ?? null) : null;
        $albumId = is_numeric($albumIdRaw) ? (int) $albumIdRaw : 0;
        expect($albumId)->toBeGreaterThan(0);

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

        $statusResult = $curl($baseUrl . '/ws.php?format=json', ['method' => 'pwg.session.getStatus']);
        $decodedStatus = json_decode($statusResult['body'], true);
        $statusResultData = is_array($decodedStatus) ? ($decodedStatus['result'] ?? null) : null;
        $pwgTokenRaw = is_array($statusResultData) ? ($statusResultData['pwg_token'] ?? null) : null;
        $pwgToken = is_string($pwgTokenRaw) || is_int($pwgTokenRaw) ? (string) $pwgTokenRaw : '';
        expect($pwgToken)->not->toBe('');

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
        // same mechanism this session's own PasswordController fix
        // (a873f5ca7d) relies on for its own $_SESSION['page_errors']
        // flash. $url_self (the real redirect target, computed early in
        // __invoke() as a bare duplicatePictureUrl(), before the
        // action-handling switch ever runs) has no action/comment params
        // of its own -- match it exactly rather than reusing $editUrl.
        $plainPictureUrl = $baseUrl . '/picture.php?/' . $imageId . '/category/' . $albumId;
        $finalBody = $curl($plainPictureUrl)['body'];

        $db = pictureDbConnect();
        $row = H::fetchAssocOrFail($db, sprintf('SELECT content, validated FROM %scomments WHERE id = %d', pictureDbPrefix(), $commentId));
        $db->close();
        @unlink($userSession['cookieJar']);

        expect((int) $row['validated'])->toBe(0);
        expect($finalBody)->toContain('An administrator must authorize your comment before it becomes visible.');
        expect($finalBody)->toContain('Your comment has been registered');
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
    // Admin\PluginLoader::loadPlugins() include_once()s
    // plugins/{id}/main.inc.php for every DB-active row on every request,
    // the same real mechanism a genuine misbehaving 3rd-party plugin would
    // use. Content-marker-gated (only ever intervenes for THIS test's own
    // unique comment content) so it's a complete no-op for every other
    // concurrent request against this shared dev server while active, and
    // both the DB row and the plugin file are removed again in `finally`.
    $pluginId = 'pwgtest-picture-bogus-comment-action';
    $pluginDir = dirname(__DIR__, 2) . '/plugins/' . $pluginId;
    $marker = 'PWGTEST_BOGUS_ACTION_MARKER_' . uniqid();

    if (! is_dir($pluginDir) && ! mkdir($pluginDir, 0o777, true) && ! is_dir($pluginDir)) {
        throw new RuntimeException('failed to create plugin dir: ' . $pluginDir);
    }
    $mainFile = $pluginDir . '/main.inc.php';
    file_put_contents($mainFile, <<<PHP
        <?php

        declare(strict_types=1);

        /*
        Plugin Name: PictureController Test -- Bogus Comment Action
        Version: 1.0.0
        Description: Test-only fixture plugin (tests/Browser/PictureControllerTest.php).
        */

        \\Piwigo\\PluginConfig\\EventDispatcher::get()->addEventHandler(
            'user_comment_check',
            static function (mixed \$action, array \$comment): mixed {
                \$content = is_string(\$comment['content'] ?? null) ? \$comment['content'] : '';
                if (str_contains(\$content, '{$marker}')) {
                    return 'this-is-not-a-real-comment-action';
                }

                return \$action;
            }
        );

        PHP);

    $pluginDb = pictureDbConnect();
    $pluginDb->query(sprintf(
        "INSERT INTO %splugins (id, state, version) VALUES ('%s', 'active', '1.0.0')",
        pictureDbPrefix(),
        $pluginId
    ));
    $pluginDb->close();
    // The DB `config` cache pool has no bearing on plugin *loading* itself
    // (PluginLoader::loadPlugins() always re-queries active plugins fresh,
    // no cache layer of its own) -- no cache-clear needed here, unlike the
    // config-param tests elsewhere in this suite.

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
        $album = $adminSession['curl']($baseUrl . '/ws.php?format=json', ['method' => 'pwg.categories.add', 'name' => 'Bogus Action Album ' . uniqid()]);
        $albumData = json_decode($album['body'], true);
        $albumResult = is_array($albumData) ? ($albumData['result'] ?? null) : null;
        $albumIdRaw = is_array($albumResult) ? ($albumResult['id'] ?? null) : null;
        $albumId = is_numeric($albumIdRaw) ? (int) $albumIdRaw : 0;
        expect($albumId)->toBeGreaterThan(0);

        $image = H::makeTestImage(uniqid());
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'Bogus Action Photo');
        @unlink($image);
        @unlink($adminSession['cookieJar']);

        $commentId = pictureInsertComment($imageId, 'bogus-action-' . uniqid(), 'Original bogus-action content.', true, 3);

        $userSession = pictureCurlLoginSession('regular_user', 'regular_user_pass');
        $curl = $userSession['curl'];

        $statusResult = $curl($baseUrl . '/ws.php?format=json', ['method' => 'pwg.session.getStatus']);
        $decodedStatus = json_decode($statusResult['body'], true);
        $statusResultData = is_array($decodedStatus) ? ($decodedStatus['result'] ?? null) : null;
        $pwgTokenRaw = is_array($statusResultData) ? ($statusResultData['pwg_token'] ?? null) : null;
        $pwgToken = is_string($pwgTokenRaw) || is_int($pwgTokenRaw) ? (string) $pwgTokenRaw : '';
        expect($pwgToken)->not->toBe('');

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
        $row = H::fetchAssocOrFail($db, sprintf('SELECT content, validated FROM %scomments WHERE id = %d', pictureDbPrefix(), $commentId));
        $db->close();
        expect($row['content'])->toBe('Bogus action content update ' . $marker);
        expect((int) $row['validated'])->toBe(0);
    } finally {
        $cleanupDb = pictureDbConnect();
        $cleanupDb->query(sprintf("DELETE FROM %splugins WHERE id = '%s'", pictureDbPrefix(), $pluginId));
        $cleanupDb->close();
        @unlink($mainFile);
        @rmdir($pluginDir);
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
        $page = H::loginAsAdmin($this);
        $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Format List Album ' . uniqid()]);
        $albumResult = $album['result'] ?? null;
        if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
            throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $albumResult['id'];
        $image = H::makeTestImage(uniqid());
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'Format List Photo');
        @unlink($image);

        // A real piwigo_image_format row with no lang catalog entry for
        // 'format WEBP' (confirmed live: no core language file, en_UK or
        // otherwise, in this repo or the 16.x reference tree defines any
        // 'format <EXT>' msgid -- Lang::has()'s own true branch is
        // realistically unreachable with the shipped catalogs, so this
        // deliberately exercises the strtoupper() fallback, the one real
        // path). 2048 KB -> exactly 2.0MB (2048/1024) once
        // sprintf('%.1fMB', ...) formats it.
        pictureInsertImageFormat($imageId, 'webp', 2048);

        $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId);
        $body = H::rawWebpage($page)->content();

        // The array_unshift()-ed original entry already has its own real
        // download_url (action.php?id=...&part=e&download) set directly
        // on the literal -- the format-fallback branch
        // (`action.php?format=<id>&amp;download`) only ever applies to
        // the *other* rows built from Projection\ImageFormat::toArray(),
        // which never has a 'download_url' key at all.
        expect($body)->toMatch('/action\.php\?format=\d+&amp;download/');
        expect($body)->toContain('>WEBP<span class="downloadformatDetails"> (2.0MB)</span>');
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
    // uploadFormAllTypes() defaults false, gating the WS upload pipeline's
    // own non-image branch. Rather than flipping that global config for
    // the whole shared dev server (affecting every concurrent request
    // while active) just to exercise ONE unrelated template-var branch,
    // this uploads a real, fully-wired JPEG through the normal path (real
    // category/permission/thumbnail wiring) and then swaps its `file`/
    // `path`/`filesize` DB columns to point at a real PDF placed at that
    // same on-disk location -- exactly what PictureController itself
    // reads (extension + path + filesize), independent of how the image
    // got there.
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'PDF Viewer Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
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
    $db->query(sprintf(
        "UPDATE %simages SET file = '%s', path = '%s', filesize = %d WHERE id = %d",
        pictureDbPrefix(),
        $db->real_escape_string($pdfFilename),
        $db->real_escape_string($relativePdfPath),
        $pdfFilesizeKb,
        $imageId
    ));
    $db->close();

    try {
        $page = H::navigateOk($page, '/picture.php?/' . $imageId . '/category/' . $albumId);
        $body = H::rawWebpage($page)->content();

        expect($body)->toContain('<embed src="' . $relativePdfPath . '" type="application/pdf"');
        expect($body)->not->toContain('too large to display');
        expect($body)->toContain('<dt>Pages</dt>');
        // countPdfPages()'s own preg_match_all('/\/Page\W/', ...) on a
        // real single-page ImageMagick-converted PDF is always exactly 1
        // -- confirmed live.
        expect($body)->toMatch('/<dt>Pages<\/dt>\s*<dd>1<\/dd>/');
        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'picture.php PDF viewer');
    } finally {
        @unlink($absolutePdfPath);
    }
});
