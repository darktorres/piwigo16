<?php

declare(strict_types=1);

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
 * Comments are inserted directly into piwigo_comments (matching
 * RegenerateFixtureTest's own direct-insert shape -- pwg.images.addComment
 * requires commentable=true on the parent album, which a freshly created
 * album doesn't have) against a real, freshly uploaded photo so the
 * INNER JOIN against piwigo_image_category (comments.php's own listing
 * query) has a real row to match. Every comment uses a uniqid()-suffixed
 * author name so `author=` filtering isolates these rows from whatever
 * else the shared dev DB currently contains.
 */

function commentsDbConnect(): mysqli
{
    return new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
}

function commentsDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

function commentsInsert(int $imageId, string $author, string $content, bool $validated, ?int $authorId = null): int
{
    $db = commentsDbConnect();
    $prefix = commentsDbPrefix();
    $db->query(sprintf(
        "INSERT INTO %scomments (image_id, date, author, anonymous_id, author_id, content, validated, validation_date) VALUES (%d, NOW(), '%s', '127.0.0.8', %s, '%s', %d, %s)",
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

function commentsRowCount(int $commentId): int
{
    $db = commentsDbConnect();
    $result = $db->query(sprintf('SELECT COUNT(*) AS c FROM %scomments WHERE id = %d', commentsDbPrefix(), $commentId));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

    return is_array($row) ? (int) $row['c'] : 0;
}

function commentsValidatedFlag(int $commentId): ?int
{
    $db = commentsDbConnect();
    $result = $db->query(sprintf('SELECT validated FROM %scomments WHERE id = %d', commentsDbPrefix(), $commentId));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

    return is_array($row) ? (int) $row['validated'] : null;
}

it('lists, paginates and keyword-filters real comments for an admin', function (): void {
    $page = H::loginAsAdmin($this);

    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Comments Test Album ' . uniqid()]);
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
    expect(substr_count($html, 'class="commentElement'))->toBe(3);
    $page->assertSee($author);

    // items_number overrides the page size to exactly 1 result.
    $page = H::navigateOk($page, '/comments.php?author=' . urlencode($author) . '&items_number=1');
    $html = H::rawWebpage($page)->content();
    expect(substr_count($html, 'class="commentElement'))->toBe(1);

    // keyword narrows to the single matching comment.
    $page = H::navigateOk($page, '/comments.php?author=' . urlencode($author) . '&keyword=' . urlencode($uniqueWord));
    $html = H::rawWebpage($page)->content();
    expect(substr_count($html, 'class="commentElement'))->toBe(1);
    $page->assertSee($uniqueWord);

    // an author that matches nothing returns zero results.
    $page = H::navigateOk($page, '/comments.php?author=' . urlencode('no-such-author-' . uniqid()));
    $html = H::rawWebpage($page)->content();
    expect(substr_count($html, 'class="commentElement'))->toBe(0);

    expect(commentsValidatedFlag($unvalidatedId))->toBe(0);
});

it('lets an admin validate and delete a comment via comments.php\'s own moderation actions', function (): void {
    $page = H::loginAsAdmin($this);
    $pwgToken = H::pwgToken($page);

    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Comments Moderation Album ' . uniqid()]);
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

    expect(commentsValidatedFlag($toValidateId))->toBe(0);

    $page = H::navigateOk($page, '/comments.php?validate=' . $toValidateId . '&pwg_token=' . $pwgToken);
    expect(commentsValidatedFlag($toValidateId))->toBe(1);

    expect(commentsRowCount($toDeleteId))->toBe(1);
    $page = H::navigateOk($page, '/comments.php?delete=' . $toDeleteId . '&pwg_token=' . $pwgToken);
    expect(commentsRowCount($toDeleteId))->toBe(0);

    // Exactly the one remaining (now-validated) comment shows up under the
    // author filter.
    $page = H::navigateOk($page, '/comments.php?author=' . urlencode($author));
    $html = H::rawWebpage($page)->content();
    expect(substr_count($html, 'class="commentElement'))->toBe(1);
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

    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Comments Anon Mod Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage('Comments Anon Mod Photo');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Comments Anon Mod Photo');
    @unlink($image);

    $toDeleteId = commentsInsert($imageId, 'guest', 'Anonymous comment to delete.', true, null);

    expect(commentsRowCount($toDeleteId))->toBe(1);
    $page = H::navigateOk($page, '/comments.php?delete=' . $toDeleteId . '&pwg_token=' . $pwgToken);
    expect(commentsRowCount($toDeleteId))->toBe(0);
});
