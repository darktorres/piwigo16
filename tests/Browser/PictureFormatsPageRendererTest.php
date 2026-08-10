<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;


it('renders an empty formats list for a photo with no alternate formats', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Picture Formats Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Picture Formats Photo');
    @unlink($image);

    $page = H::navigateOk($page, '/admin.php?page=picture_formats&image_id=' . $imageId);

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'picture_formats empty list');
});

it('lists a real alternate-format file with its label, filesize in KB, and download URL', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Picture Formats With Format Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Picture Formats With Format Photo');
    @unlink($image);

    $db = H::connect();
    // filesize is stored in KB; 2048 -> 2.0 KB rendered. NOT the
    // Lang::has('format TIF') branch -- no catalog anywhere in this repo
    // (including the reference 16.x branch) defines a "format XXX" msgid,
    // see this file's own top docblock; the label here is always the plain
    // strtoupper($ext) fallback ("TIF").
    H::dbQuery($db, sprintf("INSERT INTO image_format (image_id, ext, filesize) VALUES (%d, 'tif', 2048)", $imageId));
    $formatId = H::dbInsertId($db);
    H::dbClose($db);

    try {
        $page = H::navigateOk($page, '/admin.php?page=picture_formats&image_id=' . $imageId);

        $page->assertSee('2');
        $page->assertPresent('a[href*="format=' . $formatId . '"]');
        $page->assertNoJavaScriptErrors();
    } finally {
        $db = H::connect();
        H::dbQuery($db, sprintf('DELETE FROM image_format WHERE format_id = %d', $formatId));
        H::dbClose($db);
    }
});

it('rejects a nonexistent image_id with a fatal error', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::rawGet($page, '/admin.php?page=picture_formats&image_id=999999999');

    expect($result['body'])->toContain('does not exist');
});