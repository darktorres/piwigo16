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
});
