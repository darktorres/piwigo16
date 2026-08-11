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
 * To (re)generate baselines:
 *   vendor/bin/pest tests/Browser/VisualRegressionTest.php --update-snapshots
 *
 * What keeps these baselines deterministic:
 *   - $conf['show_gt'] (page-generation-time footer) defaults to false, so
 *     it never renders and needs no special handling here.
 *   - images.hit ("Visited N times", shown on picture.php and the
 *     admin photo editor) increments on every view — including the view
 *     this very test performs — so it drifts on every run. H::freezeImageHits()
 *     pins it to a fixed value right before each screenshot that would
 *     otherwise show it (see the dedicated 'picture-1' test below and the
 *     'admin-photo-editor' test), rather than excluding it or widening the
 *     comparison tolerance.
 *   - admin-photo-editor, the admin dashboard, admin-album and admin-users
 *     all render `DateHelper::timeSince()`-based "N hours/days ago" text,
 *     computed from `Env::now()` — a test-mode-overridable "now" provider
 *     reading `PIWIGO_TEST_NOW` — so these baselines stay deterministic.
 *     Real behavior outside test mode is unaffected.
 *   - The dashboard ALSO makes two live calls to piwigo.org unrelated to
 *     the clock: `IntroSubController::getLatestNews()` (a real news-feed
 *     fetch) and `pwg.extensions.checkUpdates` (a core/extension update
 *     check), both enabled by default. `Env::now()` does nothing for
 *     these — both config keys are disabled in the fixture itself
 *     (`RegenerateFixtureTest.php`) instead.
 *   - admin-history's "Search" tab loads its results panel via an async
 *     request that can still be in flight when assertScreenshotMatches()
 *     fires despite its built-in networkidle/readyState waits.
 *     H::waitUntilHidden() polls for the '.loading' spinner to actually
 *     disappear (neither assertSee() nor assertMissing() retry — both are
 *     one-shot checks). The Search tab's default (today's date, no filter)
 *     otherwise shows whatever real guest page-views the rest of this run
 *     already logged — admin.php?page=history has no start/end GET
 *     override, so there's no way to pin that content via a URL param.
 *     H::truncateHistory() wipes history right before this one
 *     screenshot instead, the same freeze-a-narrow-DB-slice approach as
 *     freezeImageHits().
 *   - admin-tags races the same way, for a different reason:
 *     themes/admin/default/js/tags.js restores a "per page" cookie on
 *     document.ready by simulating a click on the matching pagination
 *     link, which drives a purely client-side (no network call) ~1.8s
 *     .pageLoad fade-in/fade-out + tag-box fade sequence. Handled the same
 *     way as admin-history: H::waitUntilHidden($page, '.pageLoad').
 *   - admin-dashboard's "Activity peak in the last weeks" widget
 *     (IntroSubController) is a second, distinct source of drift from the
 *     `Env::now()` mechanism above — that only freezes the WINDOW the
 *     chart queries (which weeks/days are in range), not the data rows
 *     themselves. ActivityService::log() writes each row's `occured_on`
 *     explicitly from `Env::now()`, so every activity row logged during
 *     this suite (including each H::loginAsAdmin() call reaching this
 *     screenshot) shares the same frozen clock as the chart's own window,
 *     keeping the chart's bubble positions deterministic regardless of
 *     which real calendar day the suite runs on.
 */
$routes = [
    // ── Gallery (anonymous) ──────────────────────────────────────────────
    'gallery-home' => ['/index.php', false],
    'identification' => ['/identification.php', false],
    'register' => ['/register.php', false],
    'password' => ['/password.php', false],
    'about' => ['/about.php', false],
    'tags' => ['/tags.php', false],
    // notification.php mints a new per-request feed subscription ID (see
    // NotificationController::findAvailableFeedId()) -- but the rendered
    // .tpl (notification.tpl) only ever puts that ID inside <a href>/
    // <link href> attribute values, never in visible text, so the
    // rendered pixels are stable across requests despite the underlying
    // data changing every time.
    'notification' => ['/notification.php', false],
    // nbm.php with no subscribe/unsubscribe query param is a deterministic
    // "Unknown identifier" error page (NbmController's else branch) -- no
    // per-request randomness like notification.php's feed ID, so a plain
    // baseline needs no special normalization.
    'nbm' => ['/nbm.php', false],
    'search' => ['/search.php', false],
    'comments' => ['/comments.php', false],
    'category-1' => ['/index.php?/category/1', false],
    'category-2' => ['/index.php?/category/2', false],
    'random' => ['/random.php', false],

    // ── Gallery (auth required) ──────────────────────────────────────────
    'favorites' => ['/index.php?/favorites', true],
    'profile' => ['/profile.php', true],

    // ── Admin — Dashboard ───────────────────────────────────────────────────
    'admin-dashboard' => ['/admin.php', true],

    // ── Admin — Albums ────────────────────────────────────────────────────
    'admin-albums' => ['/admin.php?page=albums', true],
    'admin-album' => ['/admin.php?page=album&cat_id=1', true],
    'admin-album-perms' => ['/admin.php?page=album&cat_id=1&tab=permissions', true],
    'admin-cat-options' => ['/admin.php?page=cat_options', true],

    // ── Admin — Photos ────────────────────────────────────────────────────
    'admin-photos-add' => ['/admin.php?page=photos_add', true],
    'admin-batch' => ['/admin.php?page=batch_manager', true],

    // ── Admin — Users ─────────────────────────────────────────────────────
    'admin-users' => ['/admin.php?page=user_list', true],
    'admin-groups' => ['/admin.php?page=group_list', true],
    'admin-group-perm' => ['/admin.php?page=group_perm&group_id=1', true],
    'admin-user-perm' => ['/admin.php?page=user_perm&user_id=1', true],

    // ── Admin — Configuration / Tools ─────────────────────────────────────
    'admin-config' => ['/admin.php?page=configuration', true],
    'admin-maintenance' => ['/admin.php?page=maintenance', true],
    'admin-history' => ['/admin.php?page=history', true],
    'admin-tags' => ['/admin.php?page=tags', true],
    'admin-comments' => ['/admin.php?page=comments', true],
    'admin-permalinks' => ['/admin.php?page=permalinks', true],
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
            // themes/admin/default/js/tags.js fires an automatic pagination
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
            // once every <img> has actually painted.
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
