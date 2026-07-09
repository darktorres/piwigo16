<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Extended admin page smoke tests — one test per page not already covered
 * by ConsoleCleanTest. A photo is created in each test that needs a valid
 * image_id (photo editor route).
 */

it('admin photo editor page loads without errors', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Smoke Test Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (!is_array($albumResult) || !isset($albumResult['id']) || !is_numeric($albumResult['id'])) {
        throw new \RuntimeException(
            'pwg.categories.add did not return a numeric id: ' . var_export($album, true)
        );
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage('Smoke Test Photo');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Smoke Test Photo');
    @unlink($image);

    $page = H::navigateOk($page, '/admin.php?page=photo&image_id=' . $imageId);
    $page->assertNoJavaScriptErrors();
});

$routes = [
    'admin comments'       => '/admin.php?page=comments',
    'admin batch_manager'  => '/admin.php?page=batch_manager',
    'admin stats'          => '/admin.php?page=stats',
    'admin rating'         => '/admin.php?page=rating',
    'admin permalinks'     => '/admin.php?page=permalinks',
];

foreach ($routes as $name => $path) {
    it("{$name} — clean (no server errors, no JS errors)", function () use ($path): void {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, $path);
        $page->assertNoJavaScriptErrors();
    });
}
