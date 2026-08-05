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
        throw new RuntimeException(
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

// P21 Config batch: picture_formats/picture_coi both need a real image_id.
it('admin picture_formats and picture_coi pages load without errors', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Smoke Test Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (!is_array($albumResult) || !isset($albumResult['id']) || !is_numeric($albumResult['id'])) {
        throw new RuntimeException(
            'pwg.categories.add did not return a numeric id: ' . var_export($album, true)
        );
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage('Smoke Test Photo');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Smoke Test Photo');
    @unlink($image);

    $page = H::navigateOk($page, '/admin.php?page=picture_formats&image_id=' . $imageId);
    $page->assertNoJavaScriptErrors();

    $page = H::navigateOk($page, '/admin.php?page=picture_coi&image_id=' . $imageId);
    $page->assertNoJavaScriptErrors();
});

$routes = [
    'admin comments'       => '/admin.php?page=comments',
    'admin batch_manager'  => '/admin.php?page=batch_manager',
    'admin stats'          => '/admin.php?page=stats',
    'admin rating'         => '/admin.php?page=rating',
    'admin permalinks'     => '/admin.php?page=permalinks',
    // P21 Upload batch: photos_add is the first page migrated to
    // AdminSubControllerInterface (PhotosAddSubController) -- all 3 tabs
    // covered since the sub-controller's own tab-allowlist affects all of
    // them, plus the invalid-tab fallback.
    'admin photos_add (direct)'       => '/admin.php?page=photos_add',
    'admin photos_add (applications)' => '/admin.php?page=photos_add&section=applications',
    'admin photos_add (ftp)'          => '/admin.php?page=photos_add&section=ftp',
    'admin photos_add (invalid tab)'  => '/admin.php?page=photos_add&section=bogus',
    // PhotosAddSubController's own backward-compatibility alias
    // ('ploader' -> 'applications', predating the current tab-name
    // scheme) -- not exercised by the "(applications)" route above,
    // which uses the real tab name directly.
    'admin photos_add (ploader alias)' => '/admin.php?page=photos_add&section=ploader',
    // P21 Albums batch: cat_list is the one page in this batch with no
    // prior Browser coverage anywhere (albums/album/cat_options already
    // covered by AdminSmokeTest/AlbumTreeTest/ConsoleCleanTest/
    // VisualRegressionTest); tab=notification exercises AlbumSubController's
    // fallback branch (album_notification.php), not covered by the
    // VisualRegressionTest routes above.
    'admin cat_list'                  => '/admin.php?page=cat_list',
    'admin album (notification tab)'  => '/admin.php?page=album&cat_id=1&tab=notification',
    // P21 Config batch: configuration is already covered by ConsoleCleanTest/
    // VisualRegressionTest; the other 7 pages in this batch have no prior
    // Browser coverage anywhere.
    'admin extend_for_templates'      => '/admin.php?page=extend_for_templates',
    'admin menubar'                   => '/admin.php?page=menubar',
    'admin site_manager'              => '/admin.php?page=site_manager',
    'admin site_update'               => '/admin.php?page=site_update&site=1',
    'admin themes_standard_pages'     => '/admin.php?page=themes_standard_pages',
    // Extension-tabs batch: plugins/themes/languages/updates and their
    // "new"/"update" tabs had no Browser coverage at all (not even a plain
    // GET) -- each dispatches to a distinct PageRenderer per
    // PluginsSubController/ThemesSubController/LanguagesSubController/
    // UpdatesSubController's own tab switch.
    'admin plugins (installed)'       => '/admin.php?page=plugins',
    'admin plugins (new)'             => '/admin.php?page=plugins&tab=new',
    'admin plugins (update)'          => '/admin.php?page=plugins&tab=update',
    'admin themes (installed)'        => '/admin.php?page=themes',
    'admin themes (new)'              => '/admin.php?page=themes&tab=new',
    'admin themes (update)'           => '/admin.php?page=themes&tab=update',
    // ThemesSubController's own 'standard_pages' tab dispatch branch --
    // distinct from the standalone /admin.php?page=themes_standard_pages
    // route (ThemesStandardPagesSubController, already covered by
    // ThemesStandardPagesPageRendererTest.php), which is the only variant
    // exercised elsewhere despite this tab being documented as "also
    // reachable" this way.
    'admin themes (standard_pages tab)' => '/admin.php?page=themes&tab=standard_pages',
    'admin languages (installed)'     => '/admin.php?page=languages',
    'admin languages (new)'           => '/admin.php?page=languages&tab=new',
    'admin languages (update)'        => '/admin.php?page=languages&tab=update',
    'admin updates (piwigo)'          => '/admin.php?page=updates',
    'admin updates (ext)'             => '/admin.php?page=updates&tab=ext',
];

foreach ($routes as $name => $path) {
    it("{$name} — clean (no server errors, no JS errors)", function () use ($path): void {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, $path);
        $page->assertNoJavaScriptErrors();
    });
}
