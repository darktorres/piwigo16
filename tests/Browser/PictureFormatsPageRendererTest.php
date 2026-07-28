<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\PictureFormatsPageRenderer (admin.php?page=picture_formats)
 * -- lists a photo's alternate-format files (RAW/PSD/TIFF alongside the
 * main JPEG).
 *
 * Not exercised: the `Lang::has('format ' . $ext)` translated-label
 * override -- confirmed via a direct grep that no `language/en_UK/*.po`
 * catalog defines any `"format XXX"` msgid at all, so this always
 * evaluates false and the plain `strtoupper($ext)` label is always used
 * in this environment's current translation data.
 */
function pictureFormatsDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

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

    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $prefix = pictureFormatsDbPrefix();
    // filesize is stored in KB; 2048 -> 2.0 KB rendered ("format TIF" is a
    // real translated label -- Lang::has('format TIF') branch).
    $db->query(sprintf(
        "INSERT INTO %simage_format (image_id, ext, filesize) VALUES (%d, 'tif', 2048)",
        $prefix,
        $imageId
    ));
    $formatId = (int) $db->insert_id;
    $db->close();

    try {
        $page = H::navigateOk($page, '/admin.php?page=picture_formats&image_id=' . $imageId);

        $page->assertSee('2');
        $page->assertPresent('a[href*="format=' . $formatId . '"]');
        $page->assertNoJavaScriptErrors();
    } finally {
        $db = new mysqli(
            (string) getenv('PIWIGO_DB_HOST'),
            (string) getenv('PIWIGO_DB_USER'),
            (string) getenv('PIWIGO_DB_PASSWORD'),
            (string) getenv('PIWIGO_DB_BASE')
        );
        $db->query(sprintf('DELETE FROM %simage_format WHERE format_id = %d', $prefix, $formatId));
        $db->close();
    }
});

it('rejects a nonexistent image_id with a fatal error', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::rawGet($page, '/admin.php?page=picture_formats&image_id=999999999');

    expect($result['body'])->toContain('does not exist');
});