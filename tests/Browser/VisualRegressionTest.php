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
 *     freezeImageHits(). The results panel calls `GET /api/v1/
 *     history/search` — this baseline may need `--update-snapshots`
 *     regeneration in a real browser environment to confirm `.loading`
 *     actually hides and the "No results" state renders.
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
 *   - admin-user-activity renders that same `activity` table as a full,
 *     unpaginated row list (unlike admin-dashboard's chart, which only
 *     needs deterministic weekly bucketing) -- every needsAuth route's
 *     H::loginAsAdmin() call above does a real, fresh login, and each one
 *     legitimately logs its own row (`performed_by` = the pre-login guest
 *     identity, not noise -- see H::truncateGuestActivity()'s own
 *     docblock), so this page's row count depends on how many needsAuth
 *     routes happen to run before it in this file. H::truncateGuestActivity()
 *     wipes those accumulated rows right before this one screenshot,
 *     same freeze-a-narrow-DB-slice approach as H::truncateHistory(). Also
 *     races the same way admin-history's search panel does: its own
 *     activity table populates via an async request behind a '.loading'
 *     spinner, so this test waits for it the same way (found live -- the
 *     previously-committed baseline had itself been captured mid-load).
 */
// notification.php mints a new per-request feed subscription ID (see
// NotificationController::findAvailableFeedId()) -- but the rendered
// .tpl (notification.latte) only ever puts that ID inside <a href>/
// <link href> attribute values, never in visible text, so the
// rendered pixels are stable across requests despite the underlying
// data changing every time.
//
// nbm.php with no subscribe/unsubscribe query param is a deterministic
// "Unknown identifier" error page (NbmController's else branch) -- no
// per-request randomness like notification.php's feed ID, so a plain
// baseline needs no special normalization.
//
// Shared with GoldenHtmlSnapshotTest.php (P31 Smarty->Latte migration's
// raw-HTML baseline) via Helpers/VisualRegressionRoutes.php -- one literal
// array so the two checks can never drift apart on route/auth coverage.
/** @var array<string, array{0: string, 1: bool}> $routes */
$routes = require __DIR__ . '/Helpers/VisualRegressionRoutes.php';

foreach ($routes as $name => [$path, $needsAuth]) {
    it("{$name} matches its visual baseline", function () use ($name, $path, $needsAuth): void {
        // Only admin-site-manager needs this restored -- see its own
        // freezeGalleriesUrl() call below for why, and
        // H::galleriesUrl()'s own docblock for why a later route in this
        // same suite invocation (admin-site-update) would otherwise see
        // the frozen placeholder leak into its own baseline.
        $originalGalleriesUrl = $name === 'admin-site-manager' ? H::galleriesUrl() : null;

        if ($name === 'admin-site-manager') {
            // See H::freezeGalleriesUrl()'s own docblock: the fixture's
            // real, checkout-specific galleries_url would otherwise make
            // this baseline fail on any checkout but the one it was
            // captured under. A synthetic, obviously-fake path -- not
            // tied to any real user/worktree -- so the baseline is
            // portable everywhere, not just wherever it happened to be
            // (re)captured; the listing view this route renders never
            // touches the filesystem for this value (no is_dir()/
            // file_exists() call on this code path), so it doesn't need
            // to resolve to a real directory.
            //
            // Must run BEFORE H::navigateOk()/H::loginAsAdmin() below,
            // not right before the screenshot -- SiteManagerSubController
            // renders the sites list synchronously, server-side, from a
            // real DB query at request time (no later client-side
            // AJAX re-fetch), so a freeze applied only after the page
            // already rendered has zero effect on what's on screen. This
            // exact bug is why the previously-checked-in baseline itself
            // still had a real, worktree-specific path baked in (a
            // *different* worktree's own path, confirmed by decoding it)
            // instead of this placeholder -- the original freeze call
            // was already too late to matter, for every prior capture.
            H::freezeGalleriesUrl('/srv/piwigo-vr-baseline/galleries/');
        }

        try {
            if ($needsAuth) {
                if ($name === 'admin-user-activity') {
                    // See H::truncateGuestActivity()'s own docblock: every
                    // needsAuth route's H::loginAsAdmin() call does a real,
                    // fresh login, and each one logs a real `activity` row --
                    // so this page's row count (and rendered height) depends
                    // on how many other needsAuth routes ran earlier in this
                    // same suite invocation, not on this test's own baseline,
                    // without this. Deliberately BEFORE H::loginAsAdmin()
                    // below, not after (unlike admin-history's truncateHistory()
                    // call): the baseline includes this test's own login row,
                    // so only the *other* routes' accumulated rows get wiped.
                    H::truncateGuestActivity();
                }

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

            if ($name === 'admin-user-activity') {
                // Same real race as admin-history above: user_activity.latte's
                // own activity table is populated via an async request, with
                // a '.loading' spinner (icon-spin6, matching admin-history's
                // own convention) shown until it resolves -- neither
                // assertScreenshotMatches()'s own networkidle wait nor
                // assertSee()/assertMissing() (both one-shot, no retry) catch
                // it. Found live: the committed baseline itself was captured
                // mid-load (empty table, spinner visible), confirmed by
                // decoding it and comparing pixel-for-pixel against a fresh,
                // fully-loaded capture.
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
                H::waitUntilImagesLoaded($page, 10.0, '.comment-img');
            }

            if ($name === 'admin-themes-standard-pages') {
                // The 5 color-theme mini-previews plus the 2 large light/dark
                // mode previews are real static <img> elements
                // (themes/standard_pages/skins/*.jpg, scaled down via CSS
                // object-fit) -- the same "networkidle fires before every <img>
                // has actually painted" race as admin-comments above, not
                // font/animation rendering non-determinism (that's already
                // handled by assertScreenshotMatches()'s own addStyleTag()).
                H::waitUntilImagesLoaded($page, 10.0, '.std_pgs_mini_previews img, .std_pgs_selected_preview img');
            }

            if (in_array($name, ['admin-cat-list', 'admin-themes-new'], true)) {
                // Playwright's virtual mouse cursor doesn't reset on navigate --
                // it's still wherever loginAsAdmin()'s own click('login') left
                // it. On admin-cat-list that position can land on a .categoryBox
                // tile, whose real jQuery .hover() binding (cat_list.js's
                // AddHoverOnAlbumActions(), unrelated to any P38 change) then
                // fires a genuine mouseenter and reveals the tile's action menu.
                // admin-themes-new has the same hazard with a different target:
                // the theme-preview grid's own hover zoom effect on whichever
                // thumbnail the stale cursor happens to land on (confirmed via
                // the saved failure screenshot -- a hover circle over the
                // "Pure_grey_plastic" tile, deterministic on that route/cursor
                // combination, reproduced in isolation on an unmodified P38-F
                // baseline, so a pre-existing race, not a rendering bug). Both
                // are the same class: hovering <body> (always singular, present
                // on every route, no hover styling of its own) moves the real
                // cursor away from the grid before the screenshot. "html>body",
                // not "body" -- GuessLocator::for() only treats a selector as
                // literal CSS when it carries a CSS special char
                // (Support\Selector::isExplicit()); a bare tag name falls
                // through to a by-text lookup instead and times out finding no
                // element with the text "body".
                H::rawWebpage($page)->hover('html>body');
            }

            $page->assertScreenshotMatches();
        } finally {
            if ($originalGalleriesUrl !== null) {
                H::freezeGalleriesUrl($originalGalleriesUrl);
            }
        }
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
