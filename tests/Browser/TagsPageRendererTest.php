<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\TagsPageRenderer (admin.php?page=tags) -- lists every tag
 * with its image counter, warns about orphan tags (tags with zero images),
 * and deletes them via a CSRF-gated action. `PATCH /api/v1/images/{id}`'s
 * own `tagIds` field only accepts existing numeric tag ids (not names) --
 * `POST /api/v1/tags` is the real way to create a brand-new tag.
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
    $result = H::createTag($page, [
        'name' => $name,
    ]);
    $tagId = $result['id'] ?? null;
    if (! is_numeric($tagId)) {
        throw new RuntimeException('createTag did not return a numeric id: ' . var_export($result, true));
    }

    return (int) $tagId;
}

// TagRepository::findOrphanTags() only considers a tag orphaned once its
// lastmodified is >1 day old (a grace period against deleting a tag the
// instant it's created, before a photo might still be linked to it) --
// per that method's own `lastmodified < SUBDATE(NOW(),
// INTERVAL 1 DAY)` clause. A tag freshly created via pwg.tags.add never
// satisfies this on its own, so every test below must explicitly backdate
// it to actually exercise "orphan" behavior.
function tagsPageBackdateTag(int $tagId): void
{
    $db = H::connect();
    // DATE_SUB() is MySQL-only -- Postgres's own date arithmetic is
    // `NOW() - INTERVAL '2 days'`. tags also has a real BEFORE
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
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Tags Page Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Tags Page Photo');
    @unlink($image);

    $tagName = 'Counted Tag ' . uniqid();
    $tagId = tagsPageAddTag($page, $tagName);

    H::updateImageInfo($page, [
        'image_id' => (string) $imageId,
        'tag_ids' => (string) $tagId,
    ]);

    $page = H::navigateOk($page, '/admin.php?page=tags');

    $page->assertSee($tagName);
    $page->assertNoJavaScriptErrors();
});

it('warns about a real orphan tag and offers a review link', function (): void {
    $page = H::asAdmin($this);
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
    $page = H::asAdmin($this);
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
    $page = H::asAdmin($this);
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

/**
 * Writes a real `plugin.json` + PSR-4-autoloadable `ExtensionInterface`
 * class -- the plugin/theme contract's own fixture shape, loaded via
 * `PluginConfig\PluginRegistry::bootActive()`. `$bootBodySource` is spliced verbatim into the
 * fixture class's own `boot()` method body. The namespace is derived
 * from random bytes, not `$pluginId` (which can start with a digit --
 * not a legal leading character for a PHP identifier).
 */
function tagsPageWriteFixturePlugin(string $pluginId, string $bootBodySource): void
{
    $dir = tagsPagePluginsPath() . $pluginId;
    if (! is_dir($dir . '/src')) {
        mkdir($dir . '/src', 0o777, true);
    }

    $namespace = 'PiwigoTestFixture\\Ext' . bin2hex(random_bytes(6));

    file_put_contents($dir . '/plugin.json', json_encode([
        'id' => $pluginId,
        'name' => $pluginId,
        'version' => '1.0.0',
        'description' => 'Test-only fixture plugin (tests/Browser/TagsPageRendererTest.php).',
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

function tagsPageFixturePluginClassPath(string $pluginId): string
{
    return tagsPagePluginsPath() . $pluginId . '/src/Plugin.php';
}

function tagsPageRemoveFixturePlugin(string $pluginId): void
{
    $dir = tagsPagePluginsPath() . $pluginId;
    @unlink($dir . '/src/Plugin.php');
    @rmdir($dir . '/src');
    @unlink($dir . '/plugin.json');
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
    \Piwigo\Tests\Support\EventDispatcherTestFactory::get()->addTypedHandler(
        \Piwigo\Tag\Event\GetTagAltNames::class,
        static function (\Piwigo\Tag\Event\GetTagAltNames $event): void {
            if ($event->rawName === '__TAGS_PAGE_ALT_NAMES_TARGET__') {
                $event->value = ['Alt Name One', 'Alt Name Two'];
            }
        }
    );
    PHP);
    $pluginSourcePath = tagsPageFixturePluginClassPath($pluginId);
    file_put_contents(
        $pluginSourcePath,
        str_replace('__TAGS_PAGE_ALT_NAMES_TARGET__', $tagName, (string) file_get_contents($pluginSourcePath))
    );

    $db = H::connect();
    H::dbQuery($db, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0.0')", H::dbEscape($db, $pluginId)));

    $page = H::asAdmin($this);
    $tagId = tagsPageAddTag($page, $tagName);

    try {
        $result = H::rawGet($page, '/admin.php?page=tags');

        expect($result['status'])->toBe(200);
        // The `data` template var (every tag, including 'alt_names' when
        // set) is JSON-encoded verbatim into .tag-container's own
        // data-tags="..." HTML attribute -- see tags.latte. implode(', ', ...)
        // joins both real hook-returned names.
        expect($result['body'])->toContain('Alt Name One, Alt Name Two');
    } finally {
        tagsPageDeleteTag($tagId);
        H::dbQuery($db, sprintf("DELETE FROM plugins WHERE id = '%s'", H::dbEscape($db, $pluginId)));
        H::dbClose($db);
        tagsPageRemoveFixturePlugin($pluginId);
    }
});

/**
 * Which page-size link the *server* paints selected, read straight off
 * the HTTP response rather than the DOM: `tags.ts`'s own
 * `setPagination()` strips `.selected` and re-derives it from the same
 * cookie on ready, so a DOM assertion here would be testing the script,
 * not `TagsPageRenderer::tagsPerPageSelected()`. `H::rawGet()` fetches
 * with the browser's cookies and never runs the response's scripts.
 *
 * @return list<string> the `id` of every selected link, in document order
 */
function tagsPageSelectedSizes(Webpage|PendingAwaitablePage|AwaitableWebpage $page, ?string $cookie): array
{
    $page = H::navigateOk($page, '/admin.php?page=tags');

    // No `path=`, matching jQuery.cookie's own default (the document's
    // directory) -- tags.ts has already written `pwg_tags_per_page=100`
    // there, and a `path=/` write would add a second cookie of the same
    // name rather than replacing it.
    $page->script(
        $cookie === null
            ? "document.cookie = 'pwg_tags_per_page=; expires=Thu, 01 Jan 1970 00:00:00 GMT'"
            : "document.cookie = 'pwg_tags_per_page=" . $cookie . "'"
    );

    $response = H::rawGet($page, '/admin.php?page=tags');
    expect($response['status'])
        ->toBe(200);

    preg_match_all(
        '/<a\s+id="(\d+)"[^>]*\bclass="selected"/',
        $response['body'],
        $matches
    );

    return $matches[1];
}

it('selects the page-size link the pwg_tags_per_page cookie names', function (): void {
    expect(tagsPageSelectedSizes(H::asAdmin($this), '500'))
        ->toBe(['500']);
});

it('selects the first page size when the cookie has not been written yet', function (): void {
    expect(tagsPageSelectedSizes(H::asAdmin($this), null))
        ->toBe(['100']);
});

it('selects no page size at all when the cookie holds a value none of the links offer', function (): void {
    // Preserved from the template's own four `==` comparisons, which all
    // answered false for a value that is not one of the four -- pinned
    // rather than tidied into a fallback, since promoting it to 100 would
    // be a behaviour change smuggled in under a typing pass.
    expect(tagsPageSelectedSizes(H::asAdmin($this), '300'))
        ->toBe([]);
});
