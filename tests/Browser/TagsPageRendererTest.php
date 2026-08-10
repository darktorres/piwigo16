<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\TagsPageRenderer (admin.php?page=tags) -- lists every tag
 * with its image counter, warns about orphan tags (tags with zero images),
 * and deletes them via a CSRF-gated action. pwg.images.setInfo's own
 * `tag_ids` param only accepts existing numeric tag ids (not names) --
 * pwg.tags.add is the real way to create a brand-new tag over the WS API.
 *
 * The 'alt_names' assign (only set when a GetTagAltNames event handler
 * returns a non-empty list) has no plugin registered to answer it
 * by default in this env, but IS reachable via a real plugin -- exercised
 * below the same way tests/Browser/PluginsInstalledPageRendererTest.php's
 * own get_admin_plugin_menu_links tests do: a throwaway, self-cleaning
 * plugin directory written directly under the live, Apache-shared
 * plugins/ root for the duration of a single it().
 */
function tagsPageAddTag(Webpage|PendingAwaitablePage|AwaitableWebpage $page, string $name): int
{
    $result = H::wsCall($page, 'pwg.tags.add', ['name' => $name]);
    $tagResult = $result['result'] ?? null;
    $tagId = is_array($tagResult) ? ($tagResult['id'] ?? null) : null;
    if (! is_numeric($tagId)) {
        throw new RuntimeException('pwg.tags.add did not return a numeric id: ' . var_export($result, true));
    }

    return (int) $tagId;
}


// TagRepository::findOrphanTags() only considers a tag orphaned once its
// lastmodified is >1 day old (a grace period against deleting a tag the
// instant it's created, before a photo might still be linked to it) --
// confirmed live via that method's own `lastmodified < SUBDATE(NOW(),
// INTERVAL 1 DAY)` clause. A tag freshly created via pwg.tags.add never
// satisfies this on its own, so every test below must explicitly backdate
// it to actually exercise "orphan" behavior.
function tagsPageBackdateTag(int $tagId): void
{
    $db = H::connect();
    // DATE_SUB() is MySQL-only -- Postgres's own date arithmetic is
    // `NOW() - INTERVAL '2 days'`. piwigo_tags also has a real BEFORE
    // UPDATE trigger (trg_tags_lastmodified, a port of MySQL's
    // `ON UPDATE CURRENT_TIMESTAMP`) that unconditionally sets
    // NEW.lastmodified = now() on every UPDATE, which would otherwise
    // silently clobber this backdated value -- same real bug already
    // found live in tools/reimport-fixture.sh's own categories.lastmodified
    // normalization. session_replication_role = replica (used the same
    // way there) suppresses the trigger for this one statement.
    $dateExpr = $db instanceof mysqli
        ? 'DATE_SUB(NOW(), INTERVAL 2 DAY)'
        : "NOW() - INTERVAL '2 days'";
    if ($db instanceof mysqli) {
        H::dbQuery($db, sprintf('UPDATE tags SET lastmodified = %s WHERE id = %d', $dateExpr, $tagId));
    } else {
        H::dbQuery($db, 'BEGIN');
        H::dbQuery($db, 'SET session_replication_role = replica');
        H::dbQuery($db, sprintf('UPDATE tags SET lastmodified = %s WHERE id = %d', $dateExpr, $tagId));
        H::dbQuery($db, 'SET session_replication_role = DEFAULT');
        H::dbQuery($db, 'COMMIT');
    }
    H::dbClose($db);
}

function tagsPageDeleteTag(int $tagId): void
{
    $db = H::connect();
    H::dbQuery($db, sprintf('DELETE FROM tags WHERE id = %d', $tagId));
    H::dbClose($db);
}

it('renders the tag list including a real tagged photo\'s counter', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Tags Page Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Tags Page Photo');
    @unlink($image);

    $tagName = 'Counted Tag ' . uniqid();
    $tagId = tagsPageAddTag($page, $tagName);

    $updateResult = H::wsCall($page, 'pwg.images.setInfo', [
        'image_id' => (string) $imageId,
        'tag_ids' => (string) $tagId,
    ]);
    expect($updateResult['stat'] ?? null)->toBe('ok');

    $page = H::navigateOk($page, '/admin.php?page=tags');

    $page->assertSee($tagName);
    $page->assertNoJavaScriptErrors();
});

it('warns about a real orphan tag and offers a review link', function (): void {
    $page = H::loginAsAdmin($this);
    $tagName = 'Orphan Tag ' . uniqid();
    $tagId = tagsPageAddTag($page, $tagName);
    tagsPageBackdateTag($tagId);

    try {
        $page = H::navigateOk($page, '/admin.php?page=tags');

        $page->assertSee('orphan tags');
        $page->assertSee($tagName);
    } finally {
        tagsPageDeleteTag($tagId);
    }
});

it('deletes every orphan tag via the CSRF-gated delete_orphans action', function (): void {
    $page = H::loginAsAdmin($this);
    $tagName = 'Deletable Orphan Tag ' . uniqid();
    $tagId = tagsPageAddTag($page, $tagName);
    tagsPageBackdateTag($tagId);

    $token = H::pwgToken($page);
    $result = H::rawGet($page, '/admin.php?page=tags&action=delete_orphans&pwg_token=' . $token);

    // redirect() is a real Location header -- opaque under fetch(manual),
    // status always 0 (see this suite's own empty_caddie test).
    expect($result['status'])->toBe(0);

    $listPage = H::navigateOk($page, '/admin.php?page=tags');
    $listPage->assertDontSee($tagName);
    $listPage->assertSee('Orphan tags deleted');
});

it('rejects a delete_orphans request without a valid CSRF token', function (): void {
    $page = H::loginAsAdmin($this);
    $tagName = 'CSRF Guarded Orphan Tag ' . uniqid();
    $tagId = tagsPageAddTag($page, $tagName);
    tagsPageBackdateTag($tagId);

    try {
        $result = H::rawGet($page, '/admin.php?page=tags&action=delete_orphans');

        expect($result['status'])->toBe(400);

        $listPage = H::navigateOk($page, '/admin.php?page=tags');
        $listPage->assertSee($tagName);
    } finally {
        tagsPageDeleteTag($tagId);
    }
});

function tagsPagePluginsPath(): string
{
    return dirname(__DIR__, 2) . '/plugins/';
}

function tagsPageWriteFixturePlugin(string $pluginId, string $mainIncPhpSource): void
{
    $dir = tagsPagePluginsPath() . $pluginId;
    if (! is_dir($dir)) {
        mkdir($dir, 0o777, true);
    }
    file_put_contents($dir . '/main.inc.php', $mainIncPhpSource);
}

function tagsPageRemoveFixturePlugin(string $pluginId): void
{
    $dir = tagsPagePluginsPath() . $pluginId;
    @unlink($dir . '/main.inc.php');
    if (is_dir($dir)) {
        rmdir($dir);
    }
}

it('joins real get_tag_alt_names hook results into a comma-separated alt_names value', function (): void {
    $pluginId = 'pwgtest-tags-alt-names';
    $tagName = 'pwgtest-tags-alt-names-target-' . uniqid();

    // Deliberately keyed off the exact raw tag name so this plugin never
    // affects any other tag's own rendering (including other Browser tests'
    // tags sharing this same live plugins/ install for the duration of
    // this it()).
    tagsPageWriteFixturePlugin($pluginId, <<<'PHP'
    <?php

    declare(strict_types=1);

    /*
    Plugin Name: Tags Page Test -- Alt Names Hook
    Version: 1.0.0
    Description: Test-only fixture plugin (tests/Browser/TagsPageRendererTest.php).
    */

    \Piwigo\Tests\Support\EventDispatcherTestFactory::get()->addTypedHandler(
        \Piwigo\Event\Tag\GetTagAltNames::class,
        static function (\Piwigo\Event\Tag\GetTagAltNames $event): \Piwigo\Event\Tag\GetTagAltNames {
            if ($event->rawName === '__TAGS_PAGE_ALT_NAMES_TARGET__') {
                return new \Piwigo\Event\Tag\GetTagAltNames(['Alt Name One', 'Alt Name Two'], $event->rawName);
            }

            return $event;
        }
    );
    PHP);
    $pluginSourcePath = tagsPagePluginsPath() . $pluginId . '/main.inc.php';
    file_put_contents(
        $pluginSourcePath,
        str_replace('__TAGS_PAGE_ALT_NAMES_TARGET__', $tagName, (string) file_get_contents($pluginSourcePath))
    );

    $db = H::connect();
    H::dbQuery($db, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0.0')", H::dbEscape($db, $pluginId)));

    $page = H::loginAsAdmin($this);
    $tagId = tagsPageAddTag($page, $tagName);

    try {
        $result = H::rawGet($page, '/admin.php?page=tags');

        expect($result['status'])->toBe(200);
        // The `data` template var (every tag, including 'alt_names' when
        // set) is JSON-encoded verbatim into .tag-container's own
        // data-tags="..." HTML attribute -- see tags.tpl. implode(', ', ...)
        // joins both real hook-returned names.
        expect($result['body'])->toContain('Alt Name One, Alt Name Two');
    } finally {
        tagsPageDeleteTag($tagId);
        H::dbQuery($db, sprintf("DELETE FROM plugins WHERE id = '%s'", H::dbEscape($db, $pluginId)));
        H::dbClose($db);
        tagsPageRemoveFixturePlugin($pluginId);
    }
});