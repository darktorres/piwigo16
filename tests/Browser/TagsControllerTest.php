<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\TagsController (tags.php) -- the front-end tag cloud/
 * letter-index browsing page.
 */
function tagsControllerAddTag(Pest\Browser\Api\Webpage|Pest\Browser\Api\PendingAwaitablePage|Pest\Browser\Api\AwaitableWebpage $page, string $name): int
{
    $result = H::wsCall($page, 'pwg.tags.add', ['name' => $name]);
    $tagResult = $result['result'] ?? null;
    $tagId = is_array($tagResult) ? ($tagResult['id'] ?? null) : null;
    if (! is_numeric($tagId)) {
        throw new RuntimeException('pwg.tags.add did not return a numeric id: ' . var_export($result, true));
    }

    return (int) $tagId;
}

it('renders the tag cloud (default display mode) with a real tag', function (): void {
    $page = H::gotoOk($this, '/tags.php');

    $page->assertSee('nature');
    $page->assertNoJavaScriptErrors();
});

it('renders the letters display mode, grouping tags by first letter', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Tags Controller Letters Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Tags Controller Letters Photo');
    @unlink($image);

    // TagService::getAvailableTags() only returns tags actually linked to
    // a visible image (an inner join against image_category/image_tag,
    // confirmed live) -- a bare pwg.tags.add row with no photo never
    // appears on this front-end page. The result is also cached for 300s
    // (Piwigo\Cache\CachePools::tagCloud()), so a freshly-tagged photo
    // still needs that pool cleared to show up within this same test run.
    $suffix = uniqid();
    $alphaTagId = tagsControllerAddTag($page, 'Alpha Tag ' . $suffix);
    $alternateTagId = tagsControllerAddTag($page, 'Alternate Tag ' . $suffix);
    $betaTagId = tagsControllerAddTag($page, 'Beta Tag ' . $suffix);

    $updateResult = H::wsCall($page, 'pwg.images.setInfo', [
        'image_id' => (string) $imageId,
        'tag_ids' => $alphaTagId . ',' . $alternateTagId . ',' . $betaTagId,
    ]);
    expect($updateResult['stat'] ?? null)->toBe('ok');

    \Piwigo\Cache\CachePools::tagCloud()->clear();

    $page = H::navigateOk($page, '/tags.php?display_mode=letters');

    $page->assertSee('Alpha Tag ' . $suffix);
    $page->assertSee('Beta Tag ' . $suffix);
    $page->assertNoJavaScriptErrors();
});

/**
 * Closes the letters-mode `! is_string($tag_name)` fallback to
 * `name_raw` (~line 94): TagService::getAvailableTags() overwrites
 * 'name' with `EventDispatcher::triggerChange('render_tag_name', ...)`'s
 * own return value, with no whitelist -- a real plugin returning a
 * non-string reaches this branch, same throwaway-fixture-plugin
 * technique AlbumSubControllerTest.php's own render_category_name test
 * establishes (an exactly analogous hook).
 */
function tagsControllerPluginsPath(): string
{
    return dirname(__DIR__, 2) . '/plugins/';
}

it('falls back to the raw tag name in letters mode when a real render_tag_name hook returns a non-string', function (): void {
    $pluginId = 'ct-tagscontroller-hook-' . uniqid();
    $dir = tagsControllerPluginsPath() . $pluginId;
    if (! is_dir($dir)) {
        mkdir($dir, 0o777, true);
    }
    file_put_contents($dir . '/main.inc.php', <<<'PHP'
    <?php

    declare(strict_types=1);

    /*
    Plugin Name: Tags Controller Test -- render_tag_name Hook
    Version: 1.0.0
    Description: Test-only fixture plugin (tests/Browser/TagsControllerTest.php).
    */

    \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler(
        'render_tag_name',
        static fn (mixed $name): mixed => null
    );
    PHP);

    $page = H::loginAsAdmin($this);
    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $prefix = getenv('PIWIGO_DB_PREFIX');
    $prefix = $prefix !== false ? $prefix : 'piwigo_';
    $db->query(sprintf(
        "INSERT INTO %splugins (id, state, version) VALUES ('%s', 'active', '1.0.0')",
        $prefix,
        $db->real_escape_string($pluginId)
    ));

    try {
        $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Tags Controller Hook Album ' . uniqid()]);
        $albumResult = $album['result'] ?? null;
        if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
            throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $albumResult['id'];
        $image = H::makeTestImage(uniqid());
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'Tags Controller Hook Photo');
        @unlink($image);

        $tagName = 'Hook Fallback Tag ' . uniqid();
        $tagId = tagsControllerAddTag($page, $tagName);
        $updateResult = H::wsCall($page, 'pwg.images.setInfo', [
            'image_id' => (string) $imageId,
            'tag_ids' => (string) $tagId,
        ]);
        expect($updateResult['stat'] ?? null)->toBe('ok');

        \Piwigo\Cache\CachePools::tagCloud()->clear();

        $page = H::navigateOk($page, '/tags.php?display_mode=letters');

        // Without the `! is_string($tag_name)` fallback, a null 'name'
        // would make mb_substr()/StringHelper::pwgTransliterate() choke
        // on a non-string and/or render an empty/broken letter grouping
        // instead of this real, raw tag name. assertSeeSettled(), not
        // $page->assertSee() -- confirmed live: assertSee()'s own polling
        // reported failure "on the page initially with the url
        // [.../identification.php]" (the pre-navigation page), a known
        // Playwright race (see BrowserTestHelpers::settledContent()).
        H::assertSeeSettled($page, $tagName);
        $page->assertNoJavaScriptErrors();
    } finally {
        $db->query(sprintf("DELETE FROM %splugins WHERE id = '%s'", $prefix, $db->real_escape_string($pluginId)));
        $db->close();
        @unlink($dir . '/main.inc.php');
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }
});

it('falls back to the default display mode for an unrecognized display_mode value', function (): void {
    $page = H::gotoOk($this, '/tags.php?display_mode=not-a-real-mode');

    $page->assertSee('nature');
    $page->assertNoJavaScriptErrors();
});

it('each tag in the cloud links to its own filtered gallery URL', function (): void {
    $page = H::gotoOk($this, '/tags.php');

    $page->assertSeeLink('nature');
});