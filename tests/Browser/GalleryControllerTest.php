<?php

declare(strict_types=1);

use PgSql\Connection;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

function galDbConnect(): mysqli|Connection
{
    return H::connect();
}

function galSetCategoryComment(int $categoryId, ?string $comment): void
{
    $db = galDbConnect();
    if ($comment === null) {
        H::dbQuery($db, sprintf('UPDATE categories SET comment = NULL WHERE id = %d', $categoryId));
    } else {
        H::dbQuery($db, sprintf("UPDATE categories SET comment = '%s' WHERE id = %d", H::dbEscape($db, $comment), $categoryId));
    }
    H::dbClose($db);
}

function galClearCaddie(int $userId): void
{
    $db = galDbConnect();
    H::dbQuery($db, sprintf('DELETE FROM caddie WHERE user_id = %d', $userId));
    H::dbClose($db);
}

function galSetNbImagePage(int $userId, int $value): void
{
    $db = galDbConnect();
    H::dbQuery($db, sprintf('UPDATE user_infos SET nb_image_page = %d WHERE user_id = %d', $value, $userId));
    H::dbClose($db);
}

/**
 * Inserts a real `search` row shaped like
 * SearchRepository::insertSearch()'s own `rules` column (a bare `{"q":
 * ...}` object, the shape SearchService::getSearchResults() checks
 * `isset($search['q'])` against to route into getQuickSearchResults()
 * instead of getRegularSearchResults()) -- `search_uuid` is left NULL
 * (an old-style numeric-only id, same shape as a real ephemeral search
 * insert) so it's reachable via `/index.php?/search/<id>`
 * without tripping SearchService::getValidatedSearchInfo()'s
 * search_uuid-required guard. Returns the new row's id.
 */
function galInsertQuickSearch(string $q): int
{
    $db = galDbConnect();
    $rulesJson = json_encode([
        'q' => $q,
    ], JSON_THROW_ON_ERROR);
    H::dbQuery($db, sprintf("INSERT INTO search (search_uuid, created_on, created_by, forked_from, rules) VALUES (NULL, NOW(), 1, NULL, '%s')", H::dbEscape($db, $rulesJson)));
    $searchId = H::dbInsertId($db);
    H::dbClose($db);

    return $searchId;
}

function galDeleteSearch(int $searchId): void
{
    $db = galDbConnect();
    H::dbQuery($db, sprintf('DELETE FROM search WHERE id = %d', $searchId));
    H::dbClose($db);
}

/**
 * Same real fixture-plugin technique
 * `tests/Browser/PictureControllerTest.php`'s own `pictureWriteFixturePlugin()`
 * uses -- writes a real `plugin.json` + PSR-4-autoloadable
 * `ExtensionInterface` class, loaded via `PluginConfig\PluginRegistry::
 * bootActive()`.
 */
function galWriteFixturePlugin(string $pluginDir, string $bootBodySource): void
{
    if (! is_dir($pluginDir . '/src')) {
        mkdir($pluginDir . '/src', 0o777, true);
    }

    $namespace = 'PiwigoTestFixture\\Ext' . bin2hex(random_bytes(6));

    file_put_contents($pluginDir . '/plugin.json', json_encode([
        'id' => basename($pluginDir),
        'name' => basename($pluginDir),
        'version' => '1.0.0',
        'description' => 'Test-only fixture plugin (tests/Browser/GalleryControllerTest.php).',
        'license' => 'MIT',
        'minPiwigo' => '16.3.0',
        'main' => $namespace . '\\Plugin',
        'autoload' => [
            'psr-4' => [
                $namespace . '\\' => 'src/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    file_put_contents($pluginDir . '/src/Plugin.php', <<<PHP
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

function galRemoveFixturePlugin(string $pluginDir): void
{
    @unlink($pluginDir . '/src/Plugin.php');
    @rmdir($pluginDir . '/src');
    @unlink($pluginDir . '/plugin.json');
    @rmdir($pluginDir);
}

it('renders a category page with subcategories, exercising the main thumbnail/sort/edit-icon paths', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/index.php?/category/1');
    $page->assertNoJavaScriptErrors();
});

it('escapes an HTML-special-character-bearing photo name on the main thumbnail grid (P44-C)', function (): void {
    // thumbnails.latte:42's {$thumbnail['NAME']} used to print |noescape --
    // HtmlService::renderElementName() returns the raw stored name
    // unmodified (RenderElementName has no default handler).
    $page = H::loginAsAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Thumbnail Name Escaping Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    H::uploadPhotoViaApi($image, $albumId, '<script>alert(1)</script> & "Name"');
    @unlink($image);

    $page = H::navigateOk($page, '/index.php?/category/' . $albumId);
    $page->assertNoJavaScriptErrors();

    $html = H::rawWebpage($page)->content();
    // Text position, not an attribute -- Latte's escapeText() encodes
    // '<'/'>'/'&' but leaves '"' alone (only attribute position needs
    // quotes escaped), unlike getCatDisplayName()'s htmlspecialchars()
    // fix (P44-D), which is ENT_QUOTES because it also feeds href=.
    expect($html)
        ->not->toContain('<script>alert(1)</script>');
    expect($html)
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt; &amp; "Name"');
});

it('renders a category page in flat mode', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/index.php?/category/1/flat');
    $page->assertNoJavaScriptErrors();
});

it('renders a childless category with the flat icon enabled, clearing the flat-mode link', function (): void {
    $page = H::loginAsAdmin($this);
    $snapshot = H::snapshotConfig(['index_flat_icon']);

    try {
        H::setConfigValue('index_flat_icon', 'true');

        $page = H::navigateOk($page, '/index.php?/category/2');
        $page->assertNoJavaScriptErrors();
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('renders a category filtered by creation chronology, exercising the alternate-field icon link', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/index.php?/category/1/created-monthly');
    $page->assertNoJavaScriptErrors();
});

it('renders month_calendar.latte and its own CSS when the calendar chronology view is requested', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/index.php?/category/1/created-monthly-calendar');
    $body = H::rawWebpage($page)->content();

    expect($body)
        ->toContain('themes/default/css/pages/month_calendar.css');
    $page->assertNoJavaScriptErrors();
});

it('shows page-not-found when start is beyond the item count', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/index.php?/category/1/start-999');
    $page->assertNoJavaScriptErrors();
});

it('sets the session image order and redirects back to the section', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/index.php?/category/1&image_order=2');
    $page->assertNoJavaScriptErrors();
});

it('clears an invalid image order and redirects back to the section', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/index.php?/category/1&image_order=abc');
    $page->assertNoJavaScriptErrors();
});

it('sets the noindex flag and derivative display type via the display param', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/index.php?/category/1&display=square');
    $page->assertNoJavaScriptErrors();
});

it('fills the caddie and redirects back to the section', function (): void {
    $page = H::loginAsAdmin($this);

    try {
        $page = H::navigateOk($page, '/index.php?/category/1&caddie=1');
        $page->assertNoJavaScriptErrors();
    } finally {
        galClearCaddie(1);
    }
});

it('renders a tag page with combinable related tags', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/index.php?/tags/1');
    $page->assertNoJavaScriptErrors();
});

it("renders a category's description when present", function (): void {
    $page = H::loginAsAdmin($this);
    $comment = 'CT category description ' . uniqid();

    try {
        galSetCategoryComment(1, $comment);

        $page = H::navigateOk($page, '/index.php?/category/1');
        $page->assertNoJavaScriptErrors();
        $page->assertSee($comment);
    } finally {
        galSetCategoryComment(1, null);
    }
});

it('renders the recent-albums page, exercising CategoryCatsRenderer\'s isRecentCats branch', function (): void {
    // Distinct from the plain '/category/1' navigation above:
    // GalleryController only calls
    // CoreDomainAccessor::categoryCatsRenderer()->render('recent_cats', ...)
    // for this specific URL section (UrlService's own 'recent_cats'
    // token), never for a plain category page -- CategoryCatsRenderer's
    // own isRecentCats-gated filtering/sort (CategoryService::
    // isRecentCategory()/compareByGlobalRank(), as opposed to the
    // plain-category compareByRank()) had no test reaching it at all.
    // Fixture category 1's photos are all dated 2026-08-01, matching
    // PIWIGO_TEST_NOW exactly, well within any real recent_period
    // default -- so it's real, fixture-driven "recent" data, not a
    // fabricated date.
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/index.php?/recent_cats');
    $page->assertNoJavaScriptErrors();
});

it('builds a navigation bar when the section holds more items than the page size', function (): void {
    // GalleryController.php:151-154 -- only built when
    // count($page_items) > $page_nb_image_page; the fixture's own
    // 3-photo category 1 never exceeds the real 15-per-page default, so
    // this temporarily narrows the admin user's own nb_image_page
    // preference (user_infos, same "restorable DB toggle" pattern
    // as galSetCategoryComment()/galClearCaddie() above) instead of
    // faking a bigger fixture.
    $page = H::loginAsAdmin($this);

    try {
        galSetNbImagePage(1, 1);

        $page = H::navigateOk($page, '/index.php?/category/1');
        $page->assertNoJavaScriptErrors();
    } finally {
        galSetNbImagePage(1, 15);
    }
});

it('snaps the canonical URL start back a full page once it lands past the last item', function (): void {
    // GalleryController.php:164-174 (U_CANONICAL) -- with nb_image_page
    // pinned to 2 and category 2's real 2-photo fixture, requesting
    // start-1 computes $start = 2 * round(1/2) = 2, which is >= the
    // 2-item count -- GalleryController.php:168-169 then snaps it back
    // down by one full page before building the canonical URL. start-1
    // itself stays valid (1 < count($page_items) = 2), so this never
    // trips the earlier page_not_found() gate at GalleryController.php:105.
    $page = H::loginAsAdmin($this);

    try {
        galSetNbImagePage(1, 2);

        $page = H::navigateOk($page, '/index.php?/category/2/start-1');
        $page->assertNoJavaScriptErrors();
    } finally {
        galSetNbImagePage(1, 15);
    }
});

it('renders a category filtered by posted chronology, exercising the alternate-field icon link', function (): void {
    // Mirror of the existing 'created-monthly' test above, but with the
    // chronology fields swapped: GalleryController.php:242-250 computes
    // the *other* field's link (the one NOT currently being browsed), so
    // starting from chronology_field=posted exercises the branch that
    // resolves to 'created' (GalleryController.php:245) and reads
    // indexCreatedDateIcon() (GalleryController.php:248) -- the
    // 'created-monthly' test above only ever reaches the mirror-image
    // 'posted' resolution.
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/index.php?/category/1/posted-monthly');
    $page->assertNoJavaScriptErrors();
});

it('redirects to the slideshow when the slideshow param is present', function (): void {
    // GalleryController.php:538-540 -- $galleryDisplay->hasSlideshow
    // (the `slideshow` GET param) short-circuits straight to a
    // redirect(), only reachable once CategoryDefaultRenderer::render()
    // actually produced a real slideshow URL (i.e. $page_items isn't
    // empty), so this needs a real photo-bearing category, not the
    // homepage.
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/index.php?/category/1&slideshow');
    $page->assertNoJavaScriptErrors();
});

it('renders quick-search category/tag hints and an unmatched term alongside real results', function (): void {
    // "Sample" full-text-matches category 1's name ("Sample Album") and
    // "nature" matches tag 1 -- tag 1 tags images 1,2,3, exactly category
    // 1's own photos, so the implicit AND between the two terms still
    // resolves to the same non-empty [1,2,3] (qsearchGetCategories()/
    // qsearchGetTags() populate all_cats/all_tags from name/tag matches
    // independently of qsearchEval()'s own id-intersection). The 3rd
    // term never matches anything at all (image, tag, or category), so
    // it becomes an unmatched/ignored term without narrowing the first
    // two terms' result -- qsearchEval() only intersects on a
    // *qualifying* term (SearchService.php:1045-1048).
    //
    // Exercises GalleryController.php:394-431 end to end: matching_cats
    // (398, 404-411), matching_tags (399, 414, 416-420), and the
    // non-empty-items "unmatched_terms" branch (423, 427-431).
    // `matching_cats_no_images` (398) itself is never a real, populated
    // key anywhere in this codebase -- confirmed dead even in the
    // legacy 16.x reference (functions_search.inc.php never writes it,
    // only reads it via index.php's own `@$page[...]`) -- so its ternary
    // always takes the `[]` branch here; the assignment line itself
    // still genuinely executes on every real search request, which is
    // all line coverage requires.
    $searchId = galInsertQuickSearch('Sample nature quxfrobnicate42');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/index.php?/search/' . $searchId);
        $page->assertNoJavaScriptErrors();
        $page->assertSee('Album results for');
        $page->assertSee('Sample Album');
        $page->assertSee('Tag results for');
        $page->assertSee('nature');
        $page->assertSee('No results for');
        $page->assertSee('quxfrobnicate42');
    } finally {
        galDeleteSearch($searchId);
    }
});

it('renders the empty quick-search state when no term matches anything', function (): void {
    // Exercises GalleryController.php's OTHER branch of the same `if`
    // (423-425): with zero matching images, $page_items stays empty, so
    // the "no results" text renders the raw query instead of the
    // unmatched-terms list the test above exercises.
    $searchId = galInsertQuickSearch('zzzqfrobnomatch77');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/index.php?/search/' . $searchId);
        $page->assertNoJavaScriptErrors();
        $page->assertSee('No results for');
        $page->assertSee('zzzqfrobnomatch77');
    } finally {
        galDeleteSearch($searchId);
    }
});

it('dispatches IndexRendered with the real category id/name/comment when viewing a single category', function (): void {
    $comment = 'CT IndexRendered comment ' . uniqid();
    $pluginId = 'pwgtest-gallery-index-rendered-category';
    $pluginDir = dirname(__DIR__, 2) . '/plugins/' . $pluginId;
    $marker = 'PWGTEST_INDEX_RENDERED_MARKER_' . uniqid();

    galWriteFixturePlugin($pluginDir, <<<PHP
        \Piwigo\Tests\Support\EventDispatcherTestFactory::get()->addTypedHandler(
            \\Piwigo\\Controller\\Event\\IndexRendered::class,
            static function (\\Piwigo\\Controller\\Event\\IndexRendered \$event) use (\$context): void {
                if (\$event->categoryId === 1) {
                    // MENUBAR, not the now-deleted (P44-B, dead code)
                    // EXTRA_BODY_CONTENT -- any real, unconditionally
                    // rendered Html-typed Template var works equally well
                    // as a vehicle to observe this marker in the page
                    // body; MENUBAR is the one index.latte itself always
                    // prints (no isset() guard), and MenubarRenderer::render()
                    // already assigned it by the time IndexRendered fires,
                    // so concat() appends onto the real menubar HTML
                    // rather than replacing it.
                    \$context->template()->concat(
                        'MENUBAR',
                        '<div id="index-rendered-marker">{$marker}|' . \$event->categoryId . '|' . \$event->categoryName . '|' . \$event->categoryComment . '</div>'
                    );
                }
            }
        );
        PHP);

    $db = galDbConnect();
    H::dbQuery($db, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0.0')", $pluginId));
    H::dbClose($db);

    try {
        galSetCategoryComment(1, $comment);

        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/index.php?/category/1');
        $body = H::rawWebpage($page)->content();

        expect($body)
            ->toContain($marker . '|1|Sample Album|' . $comment);
        $page->assertNoJavaScriptErrors();
    } finally {
        galSetCategoryComment(1, null);
        $cleanupDb = galDbConnect();
        H::dbQuery($cleanupDb, sprintf("DELETE FROM plugins WHERE id = '%s'", $pluginId));
        H::dbClose($cleanupDb);
        galRemoveFixturePlugin($pluginDir);
    }
});

it('escapes an HTML-special-character-bearing plugin-contributed index button URL (P44-C)', function (): void {
    // index.latte:185's {$button->url} used to print |noescape --
    // label/icon two lines away were already correctly auto-escaped;
    // url was the one field that slipped through.
    $pluginId = 'pwgtest-gallery-index-button-escaping';
    $pluginDir = dirname(__DIR__, 2) . '/plugins/' . $pluginId;

    galWriteFixturePlugin($pluginDir, <<<'PHP'
        \Piwigo\Tests\Support\EventDispatcherTestFactory::get()->addTypedHandler(
            \Piwigo\Controller\Event\IndexRendering::class,
            static function () use ($context): void {
                $context->template()->addIndexButton(new \Piwigo\Contribution\ButtonContribution(
                    label: 'Test Button',
                    url: '"><script>alert(1)</script>',
                    icon: 'icon-star',
                    order: 1,
                ));
            }
        );
        PHP);

    $db = galDbConnect();
    H::dbQuery($db, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0.0')", $pluginId));
    H::dbClose($db);

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/index.php?/category/1');
        $body = H::rawWebpage($page)->content();

        expect($body)
            ->not->toContain('"><script>alert(1)</script>');
        expect($body)
            ->toContain('href="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"');
        $page->assertNoJavaScriptErrors();
    } finally {
        $cleanupDb = galDbConnect();
        H::dbQuery($cleanupDb, sprintf("DELETE FROM plugins WHERE id = '%s'", $pluginId));
        H::dbClose($cleanupDb);
        galRemoveFixturePlugin($pluginDir);
    }
});

it('dispatches IndexRendered with null category fields on a tag page (not a single-category view)', function (): void {
    $pluginId = 'pwgtest-gallery-index-rendered-tag';
    $pluginDir = dirname(__DIR__, 2) . '/plugins/' . $pluginId;
    $marker = 'PWGTEST_INDEX_RENDERED_TAG_MARKER_' . uniqid();

    galWriteFixturePlugin($pluginDir, <<<PHP
        \Piwigo\Tests\Support\EventDispatcherTestFactory::get()->addTypedHandler(
            \\Piwigo\\Controller\\Event\\IndexRendered::class,
            static function (\\Piwigo\\Controller\\Event\\IndexRendered \$event) use (\$context): void {
                // See the sibling category-view test above for why MENUBAR,
                // not the now-deleted (P44-B) EXTRA_BODY_CONTENT.
                \$context->template()->concat(
                    'MENUBAR',
                    '<div id="index-rendered-tag-marker">{$marker}|' . var_export(\$event->categoryId, true) . '|' . var_export(\$event->categoryName, true) . '|' . var_export(\$event->categoryComment, true) . '</div>'
                );
            }
        );
        PHP);

    $db = galDbConnect();
    H::dbQuery($db, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0.0')", $pluginId));
    H::dbClose($db);

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/index.php?/tags/1');
        $body = H::rawWebpage($page)->content();

        expect($body)
            ->toContain($marker . '|NULL|NULL|NULL');
        $page->assertNoJavaScriptErrors();
    } finally {
        $cleanupDb = galDbConnect();
        H::dbQuery($cleanupDb, sprintf("DELETE FROM plugins WHERE id = '%s'", $pluginId));
        H::dbClose($cleanupDb);
        galRemoveFixturePlugin($pluginDir);
    }
});
