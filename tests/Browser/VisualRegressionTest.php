<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Visual regression: screenshot comparison against committed baselines
 * (Pest's native snapshot system, via assertScreenshotMatches()).
 *
 * MUST run in isolation: `vendor/bin/pest tests/Browser/VisualRegressionTest.php`.
 * Bundling with the CRUD-mutating Browser tests (AlbumCreateTest,
 * TagCrudTest, UserManagementTest, ...) drifts the sidebar's live counts
 * ("N Albums", "N Photos", "N Users") visible on the admin dashboard and
 * several list pages, producing false diffs unrelated to any real change.
 *
 * First-time generation / intentional re-baseline (P29 templates, P30 CSS):
 *   vendor/bin/pest tests/Browser/VisualRegressionTest.php --update-snapshots
 *
 * Determinism (fixed in this same commit, not a later cleanup pass — see
 * docs/PLAN-REPLAY.md's additive-only rule and this repo's own VR
 * discipline):
 *   - $conf['show_gt'] (page-generation-time footer) defaults to false in
 *     this codebase — verified empirically, not rendered — so no template
 *     change was needed for that.
 *   - piwigo_images.hit ("Visited N times", shown on picture.php and the
 *     admin photo editor) increments on every view — including the view
 *     this very test performs — so it drifts on every run. Frozen to a
 *     fixed value via H::freezeImageHits() right before each screenshot
 *     that would otherwise show it (see the dedicated 'picture-1' test
 *     below and the 'admin-photo-editor' test), not excluded or
 *     tolerance-widened.
 *   - admin-photo-editor, the admin dashboard, admin-album and admin-users
 *     all render `time_since()`-based "N hours/days ago" text
 *     (`include/functions.inc.php`) computed from a real `new DateTime()`
 *     with no override hook — previously excluded here with a claim (never
 *     verified against the code) that the dashboard's issue was a
 *     client-side Chart.js canvas needing a full mockable-clock kernel
 *     layer (P7-P12). Re-reading the actual code found that's wrong: the
 *     dashboard's "Activity peak" widget (`admin/intro.php`) is plain
 *     server-rendered Smarty from the same kind of `new DateTime()` call —
 *     no canvas at all (a genuinely separate page, `admin/themes/default/
 *     template/stats.tpl` + `stats.js`, does use Chart.js and was likely
 *     conflated with the dashboard; it's not in this suite). Fixed with
 *     `pwg_now()` (`include/env.inc.php`) — a small test-mode-overridable
 *     "now" provider reading `PIWIGO_TEST_NOW`, wired into `time_since()`
 *     and `admin/intro.php`'s activity-chart computation. Real behavior is
 *     unaffected outside test mode.
 *   - The dashboard ALSO makes two live calls to piwigo.org unrelated to
 *     the clock: `IntroSubController::getLatestNews()` (a real news-feed
 *     fetch, P23 batch 8d — was `get_piwigo_news()`) and
 *     `pwg.extensions.checkUpdates` (a core/extension update check), both
 *     enabled by default. `pwg_now()` does nothing for these — fixed
 *     separately by disabling both config keys in the fixture itself
 *     (`RegenerateFixtureTest.php`), a genuinely pre-existing gap:
 *     `AdminSmokeTest`/`ConsoleCleanTest` already visit `/admin.php` and had
 *     been silently making these live calls the whole time, unnoticed
 *     because neither screenshot-compares and a failed fetch is swallowed
 *     silently.
 *   - admin-history's "Search" tab loads its results panel via an async
 *     request (admin/themes/default/js/history.js) that can still be in
 *     flight when assertScreenshotMatches() fires despite its built-in
 *     networkidle/readyState waits — a genuine timing race, fixed with
 *     H::waitUntilHidden() polling for the '.loading' spinner to actually
 *     disappear (neither assertSee() nor assertMissing() retry — both are
 *     one-shot checks, confirmed by reading their implementations after
 *     both flaked here).
 *   - Investigating that same race surfaced a real bug, fixed at the
 *     source, not routed around: pwg.history.search
 *     (include/ws_functions/pwg.php) indexed $full_cat_path/$name_of_category
 *     with a possibly-null $line['category_id'], tripping a PHP 8.5
 *     "Using null as an array offset" deprecation that got printed straight
 *     into the JSON response body — corrupting it for every real client,
 *     not just this test (jQuery's `dataType: "JSON"` ajax call, unable to
 *     parse it, silently fell into its `error:` handler forever, so
 *     '.loading' never got hidden and this screenshot never had a chance
 *     to be deterministic in the first place).
 *   - With that fixed, the Search tab's default (today's date, no filter)
 *     legitimately shows whatever real guest page-views the rest of this
 *     very run already logged — admin/history.php has no start/end GET
 *     override, so there's no way to pin that content via a URL param.
 *     H::truncateHistory() wipes piwigo_history right before this one
 *     screenshot, the same freeze-a-narrow-DB-slice approach as
 *     freezeImageHits(), not an exclusion.
 *   - admin-tags races the same way, for a different reason:
 *     admin/themes/default/js/tags.js restores a "per page" cookie on
 *     document.ready by simulating a click on the matching pagination
 *     link, which drives a purely client-side (no network call) ~1.8s
 *     .pageLoad fade-in/fade-out + tag-box fade sequence. This had always
 *     run on every load of this page; it surfaced here (not as a
 *     pre-existing failure) once BrowserTestHelpers::navigateOk()/
 *     assertNoServerErrors() stopped going through pest-plugin-browser's
 *     assertion-retry wrapper (see that class's docblock) — the old
 *     wrapped calls incidentally spent longer than 1.8s per navigation,
 *     which had been masking this race. Fixed the same way as
 *     admin-history: H::waitUntilHidden($page, '.pageLoad').
 *   - P23 batch 6a's own admin-tags failure turned out to be a DIFFERENT,
 *     real content bug, initially misdiagnosed as a variant of the race
 *     above (the pixel-diff percentage looked similar and the failure was
 *     deterministic under git-stash A/B testing, which is also true of a
 *     real regression). Piwigo\Admin\TagsPageRenderer's first port of
 *     admin/tags.php dropped the file's `new tabsheet(); ->set_id('tags');
 *     ->assign();` block entirely — every sibling renderer in that same
 *     sub-batch (Comments/Rating/RatingUser/Menubar/Help/CatOptions) ported
 *     it correctly, Tags alone missed it. This removed the page's entire
 *     "List" tab strip from the rendered HTML, a real, permanent layout
 *     change, not a timing-dependent one — decoding the failing screenshot
 *     and diffing it against the baseline showed a missing grey tab band
 *     and every element below it shifted up, not the partial-opacity
 *     ghosting a genuine animation race would produce. Fixed at the
 *     source (TagsPageRenderer, restoring the tabsheet block), not here.
 *   - admin-dashboard's "Activity peak in the last weeks" widget
 *     (admin/intro.php) is a second, distinct source of drift from the
 *     `pwg_now()` fix noted above — that fix only froze the WINDOW the
 *     chart queries (which weeks/days are in range), not the DATA rows
 *     themselves. pwg_activity() (include/functions.inc.php) used to
 *     rely on the `occured_on` column's DEFAULT CURRENT_TIMESTAMP — real
 *     wall-clock time — so every H::loginAsAdmin() call this suite
 *     performs before reaching this screenshot logged its own 'login'
 *     row at real "now", drifting the chart's bubble positions with
 *     whichever real calendar day the suite happened to run on. Two
 *     test-harness-only fixes were tried and discarded here before
 *     landing on the real one: a blanket `DELETE FROM piwigo_activity`
 *     broke admin-album's "Created" card (which reads its own
 *     `ACTIVITY_TABLE` 'album'/'add' row via a direct query in
 *     admin/cat_modify.php); a narrower `DELETE ... WHERE action =
 *     'login'` worked but was still a test-only workaround. Fixed
 *     properly at the source instead: pwg_activity() now sets
 *     `occured_on` explicitly from pwg_now(), the same mechanism
 *     `time_since()` already used — real behavior outside test mode is
 *     unaffected. This needed no change in this file at all beyond
 *     regenerating this one baseline (the fixture's own baked-in
 *     activity rows are a static, version-controlled file untouched by
 *     this fix, so admin-album's baseline didn't need to change).
 */
$routes = [
    // ── Gallery (anonymous) ──────────────────────────────────────────────
    'gallery-home'      => ['/index.php', false],
    'identification'     => ['/identification.php', false],
    'register'           => ['/register.php', false],
    'password'           => ['/password.php', false],
    'about'              => ['/about.php', false],
    'tags'               => ['/tags.php', false],
    'search'             => ['/search.php', false],
    'comments'           => ['/comments.php', false],
    'category-1'         => ['/index.php?/category/1', false],
    'category-2'         => ['/index.php?/category/2', false],
    'random'             => ['/random.php', false],

    // ── Gallery (auth required) ──────────────────────────────────────────
    'favorites'          => ['/index.php?/favorites', true],
    'profile'            => ['/profile.php', true],

    // ── Admin — Dashboard ───────────────────────────────────────────────────
    'admin-dashboard'    => ['/admin.php', true],

    // ── Admin — Albums ────────────────────────────────────────────────────
    'admin-albums'       => ['/admin.php?page=albums', true],
    'admin-album'        => ['/admin.php?page=album&cat_id=1', true],
    'admin-album-perms'  => ['/admin.php?page=album&cat_id=1&tab=permissions', true],
    'admin-cat-options'  => ['/admin.php?page=cat_options', true],

    // ── Admin — Photos ────────────────────────────────────────────────────
    'admin-photos-add'   => ['/admin.php?page=photos_add', true],
    'admin-batch'        => ['/admin.php?page=batch_manager', true],

    // ── Admin — Users ─────────────────────────────────────────────────────
    'admin-users'        => ['/admin.php?page=user_list', true],
    'admin-groups'       => ['/admin.php?page=group_list', true],
    'admin-group-perm'   => ['/admin.php?page=group_perm&group_id=1', true],
    'admin-user-perm'    => ['/admin.php?page=user_perm&user_id=1', true],

    // ── Admin — Configuration / Tools ─────────────────────────────────────
    'admin-config'       => ['/admin.php?page=configuration', true],
    'admin-maintenance'  => ['/admin.php?page=maintenance', true],
    'admin-history'      => ['/admin.php?page=history', true],
    'admin-tags'         => ['/admin.php?page=tags', true],
    'admin-comments'     => ['/admin.php?page=comments', true],
    'admin-permalinks'   => ['/admin.php?page=permalinks', true],
];

foreach ($routes as $name => [$path, $needsAuth]) {
    it("{$name} matches its visual baseline", function () use ($name, $path, $needsAuth): void {
        if ($needsAuth) {
            $page = H::loginAsAdmin($this);

            if ($name === 'admin-history') {
                // See the class-level docblock: wipe the table this page
                // queries — AFTER logging in (the login itself logs a
                // history row) and BEFORE navigating there (its JS fires
                // the search AJAX call on document.ready) — so the
                // default (today, unfiltered) search is always empty.
                H::truncateHistory();
            }

            $page = H::navigateOk($page, $path);
        } else {
            $page = H::visitPwg($this, $path);
            H::assertNoServerErrors($page, $path);
        }

        if ($name === 'admin-history') {
            H::waitUntilHidden($page, '.loading');
        }

        if ($name === 'admin-tags') {
            // admin/themes/default/js/tags.js fires an automatic pagination
            // click on document.ready (restoring the "per page" cookie),
            // which drives a purely client-side ~1.8s .pageLoad fade-in/
            // fade-out + tag-box fade sequence — no network call involved,
            // but still a genuine race against assertScreenshotMatches().
            H::waitUntilHidden($page, '.pageLoad');
        }

        if ($name === 'admin-comments') {
            // Comment thumbnails are lazily-generated derivatives (i.php,
            // generated on first request) -- a genuine client-side loading
            // race against assertScreenshotMatches()'s own networkidle
            // wait, which resolves once outstanding requests finish, not
            // once every <img> has actually painted. Confirmed via direct
            // A/B testing (git stash) that this reproduces identically
            // with or without unrelated code changes -- pre-existing
            // fragility in this page's own thumbnail loading, not a
            // content difference.
            H::waitUntilImagesLoaded($page, 10.0);
        }

        $page->assertScreenshotMatches();
    })->group('visual-regression');
}

it('picture-1 matches its visual baseline', function (): void {
    // Freeze the hit counter this test's own navigation is about to bump —
    // see the class-level docblock.
    H::freezeImageHits(1, 5);

    $page = H::visitPwg($this, '/picture.php?/1/category/1');
    H::assertNoServerErrors($page, 'picture-1');
    $page->assertScreenshotMatches();
})->group('visual-regression');

it('admin-photo-editor matches its visual baseline', function (): void {
    // Same hit-counter freeze as picture-1 — the photo editor shows the same
    // "Visited N times" text.
    H::freezeImageHits(1, 5);

    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=photo-1');
    $page->assertScreenshotMatches();
})->group('visual-regression');
