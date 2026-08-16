<?php

declare(strict_types=1);

use PgSql\Connection;
use Piwigo\Core\StringHelper;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\CommentsController (comments.php) -- the front-end
 * "all comments" listing + per-comment admin moderation actions. This
 * project just finished migrating this controller's own $_GET/$_POST shape
 * onto Piwigo\Controller\Request\CommentsRequest (see that class and its
 * own CommentsRequestTest.php) -- this exercises the REAL listing/
 * filtering/moderation behavior built on top of it (author filter, keyword
 * filter, items_number pagination override, delete/validate moderation),
 * not just that the page loads.
 *
 * Comments are inserted directly into comments (matching
 * RegenerateFixtureTest's own direct-insert shape -- pwg.images.addComment
 * requires commentable=true on the parent album, which a freshly created
 * album doesn't have) against a real, freshly uploaded photo so the
 * INNER JOIN against image_category (comments.php's own listing
 * query) has a real row to match. Every comment uses a uniqid()-suffixed
 * author name so `author=` filtering isolates these rows from whatever
 * else the shared dev DB currently contains.
 */
function commentsDbConnect(): mysqli|Connection
{
    return H::connect();
}

function commentsInsert(int $imageId, string $author, string $content, bool $validated, ?int $authorId = null, ?string $email = null): int
{
    $db = commentsDbConnect();
    // comments.validated is a genuine `boolean` column on Postgres (not
    // the smallint-with-integer-range convention this codebase uses
    // elsewhere) -- a bare 0/1 literal is valid MySQL tinyint(1) input
    // but Postgres rejects it outright ("column is of type boolean but
    // expression is of type integer"), confirmed live. Matches
    // RegenerateFixtureTest's own $sqlTrue/$sqlFalse convention for the
    // identical column.
    $sqlTrue = $db instanceof mysqli ? '1' : 'true';
    $sqlFalse = $db instanceof mysqli ? '0' : 'false';
    H::dbQuery($db, sprintf("INSERT INTO comments (image_id, date, author, anonymous_id, author_id, content, validated, validation_date, email) VALUES (%d, NOW(), '%s', '127.0.0.8', %s, '%s', %s, %s, %s)", $imageId, H::dbEscape($db, $author), $authorId === null ? 'NULL' : (string) $authorId, H::dbEscape($db, $content), $validated ? $sqlTrue : $sqlFalse, $validated ? 'NOW()' : 'NULL', $email === null ? 'NULL' : "'" . H::dbEscape($db, $email) . "'"));
    $id = H::dbInsertId($db);
    H::dbClose($db);

    return $id;
}

function commentsRowCount(int $commentId): int
{
    $db = commentsDbConnect();
    $row = H::dbFetchAssoc($db, sprintf('SELECT COUNT(*) AS c FROM comments WHERE id = %d', $commentId));
    H::dbClose($db);

    return is_array($row) ? (int) $row['c'] : 0;
}

function commentsValidatedFlag(int $commentId): ?int
{
    $db = commentsDbConnect();
    $row = H::dbFetchAssoc($db, sprintf('SELECT validated FROM comments WHERE id = %d', $commentId));
    H::dbClose($db);

    // validated is a genuine boolean column -- pg_fetch_assoc() returns
    // it as the string 't'/'f', which a naive (int) cast silently
    // mishandles (both (int) 'f' and (int) 't' are 0). H::dbToBool()
    // normalizes it correctly on either driver before re-widening to the
    // int this function's own callers assert against (->toBe(0)/->toBe(1)).
    return is_array($row) ? (H::dbToBool($row['validated']) ? 1 : 0) : null;
}

it('lists, paginates and keyword-filters real comments for an admin', function (): void {
    $page = H::loginAsAdmin($this);

    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Comments Test Album ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage('Comments Test Photo');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Comments Test Photo');
    @unlink($image);

    $author = 'browser-comments-author-' . uniqid();
    $uniqueWord = 'zzzcommentkeyword' . uniqid();

    commentsInsert($imageId, $author, 'First comment, nothing special.', true);
    commentsInsert($imageId, $author, 'Second comment mentions ' . $uniqueWord . ' uniquely.', true);
    $unvalidatedId = commentsInsert($imageId, $author, 'Third comment, awaiting moderation.', false);

    // Admin sees all 3 (validated=1 restriction only applies to non-admins).
    $page = H::navigateOk($page, '/comments.php?author=' . urlencode($author));
    $html = H::rawWebpage($page)->content();
    expect(substr_count($html, 'class="commentElement'))
        ->toBe(3);
    $page->assertSee($author);

    // items_number overrides the page size to exactly 1 result.
    $page = H::navigateOk($page, '/comments.php?author=' . urlencode($author) . '&items_number=1');
    $html = H::rawWebpage($page)->content();
    expect(substr_count($html, 'class="commentElement'))
        ->toBe(1);

    // keyword narrows to the single matching comment.
    $page = H::navigateOk($page, '/comments.php?author=' . urlencode($author) . '&keyword=' . urlencode($uniqueWord));
    $html = H::rawWebpage($page)->content();
    expect(substr_count($html, 'class="commentElement'))
        ->toBe(1);
    $page->assertSee($uniqueWord);

    // an author that matches nothing returns zero results.
    $page = H::navigateOk($page, '/comments.php?author=' . urlencode('no-such-author-' . uniqid()));
    $html = H::rawWebpage($page)->content();
    expect(substr_count($html, 'class="commentElement'))
        ->toBe(0);

    expect(commentsValidatedFlag($unvalidatedId))
        ->toBe(0);
});

it('lets an admin validate and delete a comment via comments.php\'s own moderation actions', function (): void {
    $page = H::loginAsAdmin($this);
    $pwgToken = H::pwgToken($page);

    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Comments Moderation Album ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage('Comments Moderation Photo');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Comments Moderation Photo');
    @unlink($image);

    // author_id=3 (regular_user, a real registered account).
    $author = 'browser-comments-mod-' . uniqid();
    $toValidateId = commentsInsert($imageId, $author, 'Please validate me.', false, 3);
    $toDeleteId = commentsInsert($imageId, $author, 'Please delete me.', true, 3);

    expect(commentsValidatedFlag($toValidateId))
        ->toBe(0);

    $page = H::navigateOk($page, '/comments.php?validate=' . $toValidateId . '&pwg_token=' . $pwgToken);
    expect(commentsValidatedFlag($toValidateId))
        ->toBe(1);

    expect(commentsRowCount($toDeleteId))
        ->toBe(1);
    $page = H::navigateOk($page, '/comments.php?delete=' . $toDeleteId . '&pwg_token=' . $pwgToken);
    expect(commentsRowCount($toDeleteId))
        ->toBe(0);

    // Exactly the one remaining (now-validated) comment shows up under the
    // author filter.
    $page = H::navigateOk($page, '/comments.php?author=' . urlencode($author));
    $html = H::rawWebpage($page)->content();
    expect(substr_count($html, 'class="commentElement'))
        ->toBe(1);
});

it('lets an admin delete an anonymous (NULL author_id) comment via comments.php\'s moderation action', function (): void {
    // Regression test for a fixed bug: comments.php's own moderation-action
    // path (unlike its plain listing path, which narrows a NULL author_id
    // to a safe -1 sentinel) calls CommentService::getCommentAuthorId(),
    // which used to collapse a NULL author_id down to the same `false`
    // sentinel as "comment not found" and crash with an uncaught TypeError
    // against AccessControl::canManageComment()'s then-`int|string`
    // parameter -- see PictureControllerTest.php's own regression test for
    // the full trace of this same underlying issue. getCommentAuthorId()
    // now returns `null` for this case and canManageComment() now accepts
    // it explicitly.
    $page = H::loginAsAdmin($this);
    $pwgToken = H::pwgToken($page);

    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Comments Anon Mod Album ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage('Comments Anon Mod Photo');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Comments Anon Mod Photo');
    @unlink($image);

    $toDeleteId = commentsInsert($imageId, 'guest', 'Anonymous comment to delete.', true, null);

    expect(commentsRowCount($toDeleteId))
        ->toBe(1);
    $page = H::navigateOk($page, '/comments.php?delete=' . $toDeleteId . '&pwg_token=' . $pwgToken);
    expect(commentsRowCount($toDeleteId))
        ->toBe(0);
});

it('returns a real 404 when comments are globally disabled', function (): void {
    $snapshot = H::snapshotConfig(['activate_comments']);

    try {
        H::setConfigValue('activate_comments', 'false');

        expect(H::httpStatus('/comments.php'))->toBe(404);
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('injects a non-standard comments_page_nb_comments value into the item_number select options', function (): void {
    $snapshot = H::snapshotConfig(['comments_page_nb_comments']);

    try {
        // Not one of the 4 hardcoded defaults (5/10/20/50) -- forces
        // __invoke()'s own "insert it into the list" rewrite loop.
        H::setConfigValue('comments_page_nb_comments', '7');

        $page = H::gotoOk($this, '/comments.php');
        $page->assertPresent('select[name="items_number"] option[value="7"]');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('filters comments by category (including subcats) and treats an unknown category as zero matches', function (): void {
    $page = H::loginAsAdmin($this);

    $parent = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Comments CatFilter Parent ' . uniqid(),
    ]);
    $parentResult = $parent['result'] ?? null;
    if (! is_array($parentResult) || ! is_numeric($parentResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($parent, true));
    }
    $parentId = (int) $parentResult['id'];

    $child = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Comments CatFilter Child ' . uniqid(),
        'parent' => (string) $parentId,
    ]);
    $childResult = $child['result'] ?? null;
    if (! is_array($childResult) || ! is_numeric($childResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($child, true));
    }
    $childId = (int) $childResult['id'];

    $image = H::makeTestImage('Comments CatFilter Photo');
    $imageId = H::uploadPhotoViaApi($image, $childId, 'Comments CatFilter Photo');
    @unlink($image);

    $author = 'browser-comments-catfilter-' . uniqid();
    commentsInsert($imageId, $author, 'A comment in the child album.', true);

    // Filtering on the PARENT category must still surface the child
    // album's own comment (getSubcatIds() includes descendants).
    $page = H::navigateOk($page, '/comments.php?cat=' . $parentId . '&author=' . urlencode($author));
    $html = H::rawWebpage($page)->content();
    expect(substr_count($html, 'class="commentElement'))
        ->toBe(1);

    // An outright nonexistent category id: getSubcatIds() returns [],
    // narrowed to the [-1] sentinel -- zero matches, not a SQL error.
    $page = H::navigateOk($page, '/comments.php?cat=999999999&author=' . urlencode($author));
    $html = H::rawWebpage($page)->content();
    expect(substr_count($html, 'class="commentElement'))
        ->toBe(0);
    H::assertNoServerErrors($page, 'comments.php with an unknown cat id');
});

it('redirects a guest to identification.php when requesting a specific comment_id directly', function (): void {
    $page = H::gotoOk($this, '/comments.php?comment_id=1');

    $currentUrl = H::rawWebpage($page)->url();
    expect($currentUrl)
        ->toContain('identification.php');
    expect($currentUrl)
        ->toContain('redirect=');
});

it('narrows the listing to exactly one comment when an admin passes comment_id', function (): void {
    $page = H::loginAsAdmin($this);

    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Comments CommentId Album ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage('Comments CommentId Photo');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Comments CommentId Photo');
    @unlink($image);

    $author = 'browser-comments-commentid-' . uniqid();
    $firstId = commentsInsert($imageId, $author, 'The one comment_id should select.', true);
    commentsInsert($imageId, $author, 'A second comment that comment_id must exclude.', true);

    $page = H::navigateOk($page, '/comments.php?comment_id=' . $firstId . '&author=' . urlencode($author));
    $html = H::rawWebpage($page)->content();
    expect(substr_count($html, 'class="commentElement'))
        ->toBe(1);
    $page->assertSee('The one comment_id should select.');
});

it('hides an unvalidated comment from a guest while an admin still sees it', function (): void {
    $page = H::loginAsAdmin($this);

    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Comments GuestVisibility Album ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage('Comments GuestVisibility Photo');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Comments GuestVisibility Photo');
    @unlink($image);

    $author = 'browser-comments-guestvis-' . uniqid();
    commentsInsert($imageId, $author, 'Validated and guest-visible.', true);
    commentsInsert($imageId, $author, 'Unvalidated and guest-hidden.', false);

    $adminHtml = H::rawWebpage(H::navigateOk($page, '/comments.php?author=' . urlencode($author)))->content();
    expect(substr_count($adminHtml, 'class="commentElement'))
        ->toBe(2);

    $guestPage = H::gotoOk($this, '/comments.php?author=' . urlencode($author));
    $guestHtml = H::rawWebpage($guestPage)->content();
    expect(substr_count($guestHtml, 'class="commentElement'))
        ->toBe(1);
    $guestPage->assertSee('Validated and guest-visible.');
});

it('shows the edit form with a real ephemeral key for a comment the admin can manage', function (): void {
    $page = H::loginAsAdmin($this);

    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Comments EditKey Album ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage('Comments EditKey Photo');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Comments EditKey Photo');
    @unlink($image);

    $commentId = commentsInsert($imageId, 'browser-comments-editkey-' . uniqid(), 'Original content before edit.', true);

    $page = H::navigateOk($page, '/comments.php?edit=' . $commentId . '#edit_comment');
    $page->assertPresent('form#editComment input[name="key"]');
    $page->assertPresent('form#editComment input[name="content"], form#editComment textarea[name="content"]');
});

it('auto-validates an admin\'s own comment edit through the real ephemeral-key round trip', function (): void {
    $page = H::loginAsAdmin($this);

    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Comments EditSubmit Album ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage('Comments EditSubmit Photo');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Comments EditSubmit Photo');
    @unlink($image);

    $commentId = commentsInsert($imageId, 'browser-comments-editsubmit-' . uniqid(), 'Before the real edit.', true);

    $page = H::navigateOk($page, '/comments.php?edit=' . $commentId);
    $html = H::rawWebpage($page)->content();
    if (preg_match('/name="key" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Could not find the rendered ephemeral key input');
    }
    $key = html_entity_decode($matches[1]);

    // EphemeralKeyService::generate(2, ...) requires the key be at least
    // 2 real seconds old before verify() accepts it (anti-rapid-submit).
    sleep(3);

    $newContent = 'Edited via the real key round trip ' . uniqid();
    // A successful 'validate' outcome redirects (fetch's own
    // redirect:'manual' mode reports that as an opaque status of 0, not
    // 200 -- see idcGet()-style helpers elsewhere for the same caveat), so
    // this doesn't assert on adminPost()'s own return value, only on the
    // real, subsequent page load.
    H::adminPost($page, '/comments.php?edit=' . $commentId, [
        'content' => $newContent,
        'key' => $key,
        'image_id' => (string) $imageId,
        'website_url' => '',
        'pwg_token' => H::pwgToken($page),
    ]);

    $page = H::navigateOk($page, '/comments.php');
    $page->assertSee('Your comment has been registered');
});

it('rejects an edit submission carrying an invalid ephemeral key', function (): void {
    $page = H::loginAsAdmin($this);

    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Comments EditReject Album ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage('Comments EditReject Photo');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Comments EditReject Photo');
    @unlink($image);

    $commentId = commentsInsert($imageId, 'browser-comments-editreject-' . uniqid(), 'Content that must survive.', true);

    $result = H::adminPost($page, '/comments.php?edit=' . $commentId, [
        'content' => 'This must never be saved',
        'key' => 'not-a-real-ephemeral-key',
        'image_id' => (string) $imageId,
        'website_url' => '',
        'pwg_token' => H::pwgToken($page),
    ]);

    expect($result['status'])->toBe(200);
    // 'reject' never sets $perform_redirect -- the page renders inline
    // (no redirect), showing the real page_errors message instead.
    expect($result['body'])->toContain('Your comment has NOT been registered because it did not pass the validation rules');
});

it('moderates (pending validation) a non-admin author\'s own comment edit when user_can_edit_comment is enabled', function (): void {
    // comments.php paginates by comments_page_nb_comments (NOT
    // nb_comment_page -- that config key drives a different listing) and
    // only renders the inline edit form (with its "key" input) for a
    // comment that's actually on the current (default start=0) page --
    // the comments table is shared, ever-growing state across this whole
    // Browser suite run, so the freshly-inserted comment below isn't
    // guaranteed to land on page 1 by the time this test runs (confirmed
    // live: with 25+ accumulated comments, it didn't). Raising
    // comments_page_nb_comments well past any realistic accumulated count
    // sidesteps pagination entirely.
    $snapshot = H::snapshotConfig(['user_can_edit_comment', 'comments_page_nb_comments']);

    try {
        H::setConfigValue('user_can_edit_comment', 'true');
        H::setConfigValue('comments_page_nb_comments', '9999');

        $adminPage = H::loginAsAdmin($this);
        $album = H::wsCall($adminPage, 'pwg.categories.add', [
            'name' => 'Comments Moderate Album ' . uniqid(),
        ]);
        $albumResult = $album['result'] ?? null;
        if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
            throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $albumResult['id'];
        $image = H::makeTestImage('Comments Moderate Photo');
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'Comments Moderate Photo');
        @unlink($image);

        // author_id=3 is 'regular_user' -- the same account this test logs
        // in as below, so canManageComment('edit', 3) matches the current
        // user id and (with user_can_edit_comment now true) is allowed.
        $commentId = commentsInsert($imageId, 'regular_user', 'Comment I will edit myself.', true, 3);

        $userPage = H::visitPwg($this, '/identification.php');
        H::assertNoServerErrors($userPage, 'regular_user identification page');
        $userPage = $userPage->fill('username', 'regular_user')
            ->fill('password', 'regular_user_pass')
            ->click('login');
        H::assertNoServerErrors($userPage, 'regular_user post-login page');

        $userPage = H::navigateOk($userPage, '/comments.php?edit=' . $commentId);
        $html = H::rawWebpage($userPage)->content();
        if (preg_match('/name="key" value="([^"]+)"/', $html, $matches) !== 1) {
            throw new RuntimeException('Could not find the rendered ephemeral key input');
        }
        $key = html_entity_decode($matches[1]);

        sleep(3);

        // A 'moderate' outcome also redirects (see the admin-edit test
        // above for why this doesn't assert on adminPost()'s own return
        // value).
        H::adminPost($userPage, '/comments.php?edit=' . $commentId, [
            'content' => 'Edited by its own author ' . uniqid(),
            'key' => $key,
            'image_id' => (string) $imageId,
            'website_url' => '',
            'pwg_token' => H::pwgToken($userPage),
        ]);

        $userPage = H::navigateOk($userPage, '/comments.php');
        // comments_validation defaults to true and this editor isn't an
        // admin -- CommentService::updateComment() resolves 'moderate',
        // which falls through to the *same* "registered" message as
        // 'validate' but via the distinct pending-authorization message
        // first. Real po-translated wording (en_UK/common.po), not the raw
        // source literal -- "before it becomes visible.", not "...is
        // visible." (confirmed live via screenshot: this exact page
        // renders with a real HTTP request, always going through
        // RequestBootstrap's real language loading, unlike the
        // process-shared Integration-test scenario this same mismatch
        // class was found in earlier).
        $userPage->assertSee('An administrator must authorize your comment before it becomes visible.');
        $userPage->assertSee('Your comment has been registered');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('falls back to the filename-derived name when a photo has no explicit name', function (): void {
    $page = H::loginAsAdmin($this);

    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Comments NoName Album ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage('Comments NoName Photo');
    // An empty name -- pwg.images.addSimple leaves images.name NULL/empty
    // rather than inventing one, exercising the ALT text's own
    // StringHelper::getNameFromFile() fallback.
    $imageId = H::uploadPhotoViaApi($image, $albumId, '');
    @unlink($image);

    $db = commentsDbConnect();
    $row = H::dbFetchAssoc($db, sprintf('SELECT file, name FROM images WHERE id = %d', $imageId));
    H::dbClose($db);
    if (! is_array($row)) {
        throw new RuntimeException("expected a real images row for id {$imageId}");
    }
    $realName = $row['name'] ?? null;
    if (is_string($realName) && $realName !== '') {
        throw new RuntimeException('this test requires an empty images.name to exercise the fallback, got: ' . var_export($realName, true));
    }
    $expectedAlt = StringHelper::getNameFromFile((string) $row['file']);

    $author = 'browser-comments-noname-' . uniqid();
    commentsInsert($imageId, $author, 'Comment on an unnamed photo.', true);

    $page = H::navigateOk($page, '/comments.php?author=' . urlencode($author));
    $html = H::rawWebpage($page)->content();
    expect($html)
        ->toContain('alt="' . htmlspecialchars($expectedAlt) . '"');
});

it("shows an anonymous comment's own email when no linked user account has one", function (): void {
    $page = H::loginAsAdmin($this);

    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Comments GuestEmail Album ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage('Comments GuestEmail Photo');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Comments GuestEmail Photo');
    @unlink($image);

    // NULL author_id (no linked user, so the LEFT JOIN's u.email is NULL
    // too) with a real per-comment `email` column value -- the EMAIL
    // template var (admin-only) must fall back to this raw comment.email.
    $author = 'browser-comments-guestemail-' . uniqid();
    $email = 'ct-guest-' . uniqid() . '@example.test';
    commentsInsert($imageId, $author, 'Anonymous comment with its own email.', true, null, $email);

    $page = H::navigateOk($page, '/comments.php?author=' . urlencode($author));
    $page->assertPresent('a[href="mailto:' . $email . '"]');
});

/**
 * Closes the comment-edit dispatch switch's own `default:` arm (~line
 * 303) -- CommentService::updateComment()'s `user_comment_check` event
 * hook has no whitelist on its own $commentAction property value, so a
 * REAL plugin returning a well-typed UserCommentCheck with an arbitrary
 * string reaches this controller's switch with a value matching none of
 * 'moderate'/'validate'/'reject', landing on `trigger_error('Invalid
 * comment action ...', E_USER_WARNING)`.
 * ErrorCollector (installed on every real request) surfaces that as an
 * `X-PHP-Error-1` response header rather than corrupting the page body --
 * asserted on directly here via a raw in-browser fetch() (matching
 * H::adminPost()'s own technique, extended to also read response
 * headers, which neither adminPost() nor rawGet() exposes).
 */
function commentsPluginsPath(): string
{
    return dirname(__DIR__, 2) . '/plugins/';
}

/**
 * Writes a real `plugin.json` + PSR-4-autoloadable `ExtensionInterface`
 * class -- the P27 plugin/theme contract's own fixture shape, replacing
 * the legacy `main.inc.php` raw-include mechanism `Admin\PluginLoader::
 * loadPlugins()` used to run (retired in P27.4, replaced by
 * `PluginConfig\PluginRegistry::bootActive()`, which has no knowledge of
 * `main.inc.php` at all). `$bootBodySource` is spliced verbatim into the
 * fixture class's own `boot()` method body. The namespace is derived
 * from random bytes, not `$pluginId` (which can start with a digit after
 * its own `uniqid()` suffix -- not a legal leading character for a PHP
 * identifier).
 */
function commentsWriteFixturePlugin(string $pluginId, string $bootBodySource): void
{
    $dir = commentsPluginsPath() . $pluginId;
    if (! is_dir($dir . '/src')) {
        mkdir($dir . '/src', 0o777, true);
    }

    $namespace = 'PiwigoTestFixture\\Ext' . bin2hex(random_bytes(6));

    file_put_contents($dir . '/plugin.json', json_encode([
        'id' => $pluginId,
        'name' => $pluginId,
        'version' => '1.0.0',
        'description' => 'Test-only fixture plugin (tests/Browser/CommentsControllerTest.php).',
        'license' => 'MIT',
        'minPiwigo' => '16.3.0',
        'main' => $namespace . '\\Plugin',
        'autoload' => [
            'psr-4' => [
                $namespace . '\\' => 'src/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    file_put_contents($dir . '/src/Plugin.php', <<<PHP
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

function commentsRemoveFixturePlugin(string $pluginId): void
{
    $dir = commentsPluginsPath() . $pluginId;
    @unlink($dir . '/src/Plugin.php');
    @rmdir($dir . '/src');
    @unlink($dir . '/plugin.json');
    if (is_dir($dir)) {
        rmdir($dir);
    }
}

it('trigger_errors on an unrecognized comment_action from a real user_comment_check hook', function (): void {
    $pluginId = 'ct-comments-hook-' . uniqid();
    commentsWriteFixturePlugin($pluginId, <<<'PHP'
    \Piwigo\Tests\Support\EventDispatcherTestFactory::get()->addTypedHandler(
        \Piwigo\Comment\Event\UserCommentCheck::class,
        static fn (\Piwigo\Comment\Event\UserCommentCheck $event): \Piwigo\Comment\Event\UserCommentCheck => new \Piwigo\Comment\Event\UserCommentCheck('ct_unknown_action', $event->comm)
    );
    PHP);

    $db = commentsDbConnect();
    H::dbQuery($db, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0.0')", H::dbEscape($db, $pluginId)));

    try {
        $page = H::loginAsAdmin($this);
        $album = H::wsCall($page, 'pwg.categories.add', [
            'name' => 'Comments Hook Album ' . uniqid(),
        ]);
        $albumResult = $album['result'] ?? null;
        if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
            throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $albumResult['id'];
        $image = H::makeTestImage('Comments Hook Photo');
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'Comments Hook Photo');
        @unlink($image);

        $commentId = commentsInsert($imageId, 'browser-comments-hook-' . uniqid(), 'Before the hook edit.', true);

        $page = H::navigateOk($page, '/comments.php?edit=' . $commentId);
        $html = H::rawWebpage($page)->content();
        if (preg_match('/name="key" value="([^"]+)"/', $html, $matches) !== 1) {
            throw new RuntimeException('Could not find the rendered ephemeral key input');
        }
        $key = html_entity_decode($matches[1]);

        // EphemeralKeyService::generate(2, ...)'s own anti-rapid-submit
        // window, same as every other real edit-submission test above.
        sleep(3);

        $url = H::baseUrl() . '/comments.php?edit=' . $commentId;
        $body = http_build_query([
            'content' => 'This never becomes a real registered comment.',
            'key' => $key,
            'image_id' => (string) $imageId,
            'website_url' => '',
            'pwg_token' => H::pwgToken($page),
        ]);
        // Not the X-PHP-Error-1 response header -- TestErrorsController's
        // own docblock documents exactly why: it "may never surface to
        // the test client" ("a failed/redirected request" -- confirmed
        // live, this real one too: the switch's default branch falls
        // straight through into the controller's own normal full-page
        // render, which has already streamed real body output -- and
        // therefore already sent HTTP headers -- long before
        // ErrorCollector::flush()'s shutdown-function header() calls ever
        // get a chance to run; flush()'s own headers_sent() guard then
        // silently skips them.
        //
        // Also not GET /__test/errors -- confirmed live that doesn't
        // reliably work either: ErrorCollector's buffer is per-worker-
        // process, and a second, separate fetch() has no guarantee of
        // landing on the same Apache prefork worker that handled the
        // POST (empty errors array observed live). ErrorCollector::
        // writeTestErrorsLog() is the one path that's genuinely reliable
        // here: it writes synchronously inside handleError() itself (not
        // deferred to the shutdown function), straight to a real shared
        // file (_data/logs/test_errors.log) both the Apache worker and
        // this CLI test process can read -- no header/worker-affinity
        // assumption needed.
        $js = <<<JS
        fetch('{$url}', {
            method: 'POST',
            redirect: 'manual',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: '{$body}'
        }).then(r => r.status)
        JS;
        $status = $page->script($js);
        expect($status)
            ->toBe(200);

        $testErrorsLogPath = dirname(__DIR__, 2) . '/_data/logs/test_errors.log';
        $testErrorsLog = is_file($testErrorsLogPath) ? file_get_contents($testErrorsLogPath) : false;
        if (! is_string($testErrorsLog)) {
            throw new RuntimeException("Could not read {$testErrorsLogPath}");
        }
        expect($testErrorsLog)
            ->toContain('Invalid comment action ct_unknown_action');
    } finally {
        $db = commentsDbConnect();
        H::dbQuery($db, sprintf("DELETE FROM plugins WHERE id = '%s'", H::dbEscape($db, $pluginId)));
        H::dbClose($db);
        commentsRemoveFixturePlugin($pluginId);
    }
});
