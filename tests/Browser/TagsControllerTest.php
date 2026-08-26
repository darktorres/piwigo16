<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\TagCloudCachePool;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Matches config/container.php's own TagCloudCachePool::class factory
 * entry's literal namespace/TTL exactly -- built directly here, not
 * container-resolved: this Browser suite exercises the real app over
 * HTTP, with no Kernel::boot() of its own in this test process, and a
 * throwaway instance pointed at the same namespace clears the identical
 * shared backing (see AbstractNamedCachePool's own docblock -- pool
 * identity carries no correctness risk).
 */
function tagsControllerTestTagCloudCachePool(): TagCloudCachePool
{
    return new TagCloudCachePool(CacheFactory::create(namespace: 'piwigo.tag_cloud', defaultLifetime: 300));
}

/**
 * Piwigo\Controller\TagsController (tags.php) -- the front-end tag cloud/
 * letter-index browsing page.
 */
function tagsControllerAddTag(Webpage|PendingAwaitablePage|AwaitableWebpage $page, string $name): int
{
    $result = H::createTag($page, [
        'name' => $name,
    ]);
    $tagId = $result['id'] ?? null;
    if (! is_numeric($tagId)) {
        throw new RuntimeException('createTag did not return a numeric id: ' . var_export($result, true));
    }

    return (int) $tagId;
}

it('renders the tag cloud (default display mode) with a real tag', function (): void {
    $page = H::gotoOk($this, '/tags.php');

    $page->assertSee('nature');
    $page->assertNoJavaScriptErrors();
});

it('renders the letters display mode, grouping tags by first letter', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Tags Controller Letters Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Tags Controller Letters Photo');
    @unlink($image);

    // TagService::getAvailableTags() only returns tags actually linked to
    // a visible image (an inner join against image_category/image_tag,
    // confirmed live) -- a bare pwg.tags.add row with no photo never
    // appears on this front-end page. The result is also cached for 300s
    // (Piwigo\Cache\TagCloudCachePool), so a freshly-tagged photo
    // still needs that pool cleared to show up within this same test run.
    $suffix = uniqid();
    $alphaTagId = tagsControllerAddTag($page, 'Alpha Tag ' . $suffix);
    $alternateTagId = tagsControllerAddTag($page, 'Alternate Tag ' . $suffix);
    $betaTagId = tagsControllerAddTag($page, 'Beta Tag ' . $suffix);

    H::updateImageInfo($page, [
        'image_id' => (string) $imageId,
        'tag_ids' => $alphaTagId . ',' . $alternateTagId . ',' . $betaTagId,
    ]);

    tagsControllerTestTagCloudCachePool()
        ->clear();

    $page = H::navigateOk($page, '/tags.php?display_mode=letters');

    $page->assertSee('Alpha Tag ' . $suffix);
    $page->assertSee('Beta Tag ' . $suffix);
    $page->assertNoJavaScriptErrors();
});

/**
 * Proves dispatch()'s resilience to a misbehaving handler for the
 * `RenderTagName` event: TagService::getAvailableTags() dispatches it
 * with no whitelist -- a real plugin handler returning something other
 * than a RenderTagName instance (without mutating $event->tagName)
 * reaches this branch, same throwaway-fixture-plugin technique
 * AlbumSubControllerTest.php's own RenderCategoryName test establishes
 * (an exactly analogous hook).
 */
function tagsControllerPluginsPath(): string
{
    return dirname(__DIR__, 2) . '/plugins/';
}

/**
 * Writes a real `plugin.json` + PSR-4-autoloadable `ExtensionInterface`
 * class -- the plugin/theme contract's own fixture shape, loaded via
 * `PluginConfig\PluginRegistry::bootActive()`. `$bootBodySource` is spliced verbatim into the
 * fixture class's own `boot()` method body. The namespace is derived
 * from random bytes, not `$pluginId` (which can start with a digit after
 * its own `uniqid()` suffix -- not a legal leading character for a PHP
 * identifier).
 */
function tagsControllerWriteFixturePlugin(string $pluginId, string $bootBodySource): void
{
    $dir = tagsControllerPluginsPath() . $pluginId;
    if (! is_dir($dir . '/src')) {
        mkdir($dir . '/src', 0o777, true);
    }

    $namespace = 'PiwigoTestFixture\\Ext' . bin2hex(random_bytes(6));

    file_put_contents($dir . '/plugin.json', json_encode([
        'id' => $pluginId,
        'name' => $pluginId,
        'version' => '1.0.0',
        'description' => 'Test-only fixture plugin (tests/Browser/TagsControllerTest.php).',
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

function tagsControllerRemoveFixturePlugin(string $pluginId): void
{
    $dir = tagsControllerPluginsPath() . $pluginId;
    @unlink($dir . '/src/Plugin.php');
    @rmdir($dir . '/src');
    @unlink($dir . '/plugin.json');
    if (is_dir($dir)) {
        rmdir($dir);
    }
}

it('renders the real tag name when a render_tag_name hook returns something other than a RenderTagName instance', function (): void {
    // RenderTagName is dispatched from many TagService call sites, not
    // just this one page -- the plugin's DB activation row is inserted
    // only around the one navigation under test, strictly AFTER login and
    // every setup WS call (album/photo/tag creation, setInfo) and removed
    // again strictly BEFORE any other request, so it can't break an
    // unrelated call that happens to dispatch the same event.
    $pluginId = 'ct-tagscontroller-hook-' . uniqid();
    tagsControllerWriteFixturePlugin($pluginId, <<<'PHP'
    \Piwigo\Tests\Support\EventDispatcherTestFactory::get()->addTypedHandler(
        \Piwigo\Tag\Event\RenderTagName::class,
        static fn (mixed $event): mixed => null
    );
    PHP);

    $page = H::asAdmin($this);
    $db = H::connect();

    try {
        $album = H::createCategory($page, [
            'name' => 'Tags Controller Hook Album ' . uniqid(),
        ]);
        if (! is_numeric($album['id'] ?? null)) {
            throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $album['id'];
        $image = H::makeTestImage(uniqid());
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'Tags Controller Hook Photo');
        @unlink($image);

        $tagName = 'Hook Fallback Tag ' . uniqid();
        $tagId = tagsControllerAddTag($page, $tagName);
        H::updateImageInfo($page, [
            'image_id' => (string) $imageId,
            'tag_ids' => (string) $tagId,
        ]);

        tagsControllerTestTagCloudCachePool()
            ->clear();

        H::dbQuery($db, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0.0')", H::dbEscape($db, $pluginId)));

        try {
            // dispatch() never reads a handler's return value (Plan 2
            // Stage A step 2), so a misbehaving handler that returns
            // garbage without touching $event->tagName leaves it exactly
            // as TagService set it -- the page renders normally with the
            // real tag name, rather than crashing or dropping it.
            $response = H::rawGet($page, '/tags.php?display_mode=letters');
            expect($response['status'])->toBe(200);
            expect($response['body'])->toContain($tagName);
        } finally {
            H::dbQuery($db, sprintf("DELETE FROM plugins WHERE id = '%s'", H::dbEscape($db, $pluginId)));
        }
    } finally {
        H::dbClose($db);
        tagsControllerRemoveFixturePlugin($pluginId);
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
