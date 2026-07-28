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
    // $commentAction = 'reject' branch (confirmed live): a 200 response,
    // no error surfaced (the $_SESSION['page_errors'] write that branch
    // makes has no reader anywhere in this codebase, confirmed via a
    // full-repo grep -- same class of dead-write gap as this file's own
    // moderate/validate branches use), and the comment's content column
    // left untouched.
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
    $ch = curl_init($picUrl);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $session['cookieJar']);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $session['cookieJar']);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [...H::testHeaders(), 'Cookie: picture_deriv=large']);
    $firstBody = curl_exec($ch);
    $firstStatus = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    unset($ch);
    expect($firstStatus)->toBe(200);
    expect(is_string($firstBody) ? $firstBody : '')->not->toContain('Fatal error');

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
