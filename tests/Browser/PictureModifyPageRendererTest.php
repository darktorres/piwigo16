<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\PictureModifyPageRenderer (admin.php?page=photo, the
 * per-photo "Properties" tab) -- AdminExtendedSmokeTest.php's own
 * data-driven smoke sweep already visits this tab with a plain GET, so
 * this file focuses on the actual metadata-update submission this
 * renderer's own request-handling branch (~150 uncovered lines) never
 * gets exercised by that bare visit: title/author/comment/level/
 * date_creation, tag assignment, and moving the photo into another album.
 */

function pictureModifyDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

function pictureModifyDbConnect(): mysqli
{
    return new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
}

/** @return array{name: ?string, author: ?string, comment: ?string, level: int, date_creation: ?string}|null */
function pictureModifyImageRow(int $imageId): ?array
{
    $db = pictureModifyDbConnect();
    $result = $db->query(sprintf(
        'SELECT name, author, comment, level, date_creation FROM %simages WHERE id = %d',
        pictureModifyDbPrefix(),
        $imageId
    ));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

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
    $result = $db->query(sprintf(
        'SELECT COUNT(*) AS c FROM %simage_tag WHERE image_id = %d AND tag_id = %d',
        pictureModifyDbPrefix(),
        $imageId,
        $tagId
    ));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

    return is_array($row) && (int) $row['c'] > 0;
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
    $row = $db->query(sprintf('SELECT representative_picture_id FROM %scategories WHERE id = %d', $prefix, $albumId));
    $assoc = $row instanceof mysqli_result ? $row->fetch_assoc() : null;
    $db->close();
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
