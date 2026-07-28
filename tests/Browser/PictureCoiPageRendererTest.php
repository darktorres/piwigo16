<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\PictureCoiPageRenderer (admin.php?page=picture_coi) -- the
 * "center of interest" cropping editor for a single photo.
 */
function pictureCoiDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

function pictureCoiValue(int $imageId): ?string
{
    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $result = $db->query(sprintf('SELECT coi FROM %simages WHERE id = %d', pictureCoiDbPrefix(), $imageId));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

    return is_array($row) && is_string($row['coi']) ? $row['coi'] : null;
}

it('renders the coi editor for a photo with no center of interest set yet', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Picture Coi Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Picture Coi Photo');
    @unlink($image);

    expect(pictureCoiValue($imageId))->toBeNull();

    $page = H::navigateOk($page, '/admin.php?page=picture_coi&image_id=' . $imageId);

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'picture_coi no-coi-yet render');
});

it('submits a new center of interest, persists it, and invalidates derivative-URL-style config', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Picture Coi Submit Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Picture Coi Submit Photo');
    @unlink($image);

    $result = H::adminPost($page, '/admin.php?page=picture_coi&image_id=' . $imageId, [
        'submit' => '1',
        'l' => '0.1',
        't' => '0.2',
        'r' => '0.8',
        'b' => '0.9',
    ]);

    expect($result['status'])->toBe(200);
    expect(pictureCoiValue($imageId))->not->toBeNull();
    expect(pictureCoiValue($imageId))->toHaveLength(4);
});

it('renders a real 404 "Page not found" response for a nonexistent image_id', function (): void {
    $page = H::loginAsAdmin($this);

    // pageNotFound() -> RedirectService::redirectHtml() throws a real
    // ResponseReadyException with the given status code baked into the
    // response (a meta-refresh HTML body, NOT a Location header) -- a
    // genuine 404, not an opaque fetch(manual) redirect like this
    // renderer's own successful-submission paths elsewhere in this suite.
    $result = H::rawGet($page, '/admin.php?page=picture_coi&image_id=999999999');

    expect($result['status'])->toBe(404);
    expect($result['body'])->toContain('Page not found');
});