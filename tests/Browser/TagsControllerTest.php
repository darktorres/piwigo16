<?php

declare(strict_types=1);

use Pest\Browser\Api\Webpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\AwaitableWebpage;
use Piwigo\Cache\CachePools;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\TagsController (tags.php) -- the front-end tag cloud/
 * letter-index browsing page.
 */
function tagsControllerAddTag(Webpage|PendingAwaitablePage|AwaitableWebpage $page, string $name): int
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

    CachePools::tagCloud()->clear();

    $page = H::navigateOk($page, '/tags.php?display_mode=letters');

    $page->assertSee('Alpha Tag ' . $suffix);
    $page->assertSee('Beta Tag ' . $suffix);
    $page->assertNoJavaScriptErrors();
});

/**
 * Closes dispatchChange()'s own instanceof-enforcement branch for the
 * `RenderTagName` event: TagService::getAvailableTags() dispatches it with
 * no whitelist -- a real plugin handler returning something other than a
 * RenderTagName instance reaches this branch, same throwaway-fixture-
 * plugin technique AlbumSubControllerTest.php's own RenderCategoryName
 * test establishes (an exactly analogous hook).
 */
function tagsControllerPluginsPath(): string
{
    return dirname(__DIR__, 2) . '/plugins/';
}

it('fatal-errors instead of silently swallowing a real render_tag_name hook that returns something other than a RenderTagName instance', function (): void {
    // RenderTagName is dispatched from many TagService call sites, not
    // just this one page -- the plugin's DB activation row is inserted
    // only around the one navigation under test, strictly AFTER login and
    // every setup WS call (album/photo/tag creation, setInfo) and removed
    // again strictly BEFORE any other request, so it can't break an
    // unrelated call that happens to dispatch the same event.
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

    \Piwigo\Tests\Support\EventDispatcherTestFactory::get()->addTypedHandler(
        \Piwigo\Event\Tag\RenderTagName::class,
        static fn (mixed $event): mixed => null
    );
    PHP);

    $page = H::loginAsAdmin($this);
    $db = H::connect();

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

        CachePools::tagCloud()->clear();

        H::dbQuery($db, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0.0')", H::dbEscape($db, $pluginId)));

        try {
            // dispatchChange() now enforces its own instanceof contract --
            // a misbehaving handler makes the request fail loud (an HTTP
            // 500) rather than silently degrading. display_errors is off
            // site-wide (Core\ErrorCollector::install() forces it, and
            // php.ini already has it off too), so the response body itself
            // carries no exception detail to assert on -- the status code
            // is the only reliable, environment-independent signal.
            $response = H::rawGet($page, '/tags.php?display_mode=letters');
            expect($response['status'])->toBe(500);
        } finally {
            H::dbQuery($db, sprintf("DELETE FROM plugins WHERE id = '%s'", H::dbEscape($db, $pluginId)));
        }
    } finally {
        H::dbClose($db);
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
