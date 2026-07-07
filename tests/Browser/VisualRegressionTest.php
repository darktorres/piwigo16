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
 *     below), not excluded or tolerance-widened.
 *   - admin-photo-editor, the admin dashboard ('/admin.php'), admin-album
 *     ('page=album') and admin-users ('page=user_list') ARE excluded —
 *     all render server-computed wall-clock-relative content with no
 *     available freeze point this phase: the photo editor shows "posted
 *     N hours/days ago" (time_since(), computed from real time() at render
 *     time), the dashboard's "Activity peak" widget draws week-of-year
 *     labels into a Chart.js canvas from the current date, admin-album
 *     shows "Created/Modified N hours ago" for the album, and admin-users
 *     shows "Registered N hours ago" per user. None of this is template
 *     data that can be pinned the way the hit counter can; a real fix
 *     needs a mockable clock (later phase — P7-P12 kernel work introduces
 *     one). Documented exclusion, not a laundered diff. Found by actually
 *     re-running the suite and reading the expected/actual diff images
 *     (P3), not assumed from P2's original investigation.
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

    // ── Admin — Albums ────────────────────────────────────────────────────
    'admin-albums'       => ['/admin.php?page=albums', true],
    'admin-album-perms'  => ['/admin.php?page=album&cat_id=1&tab=permissions', true],
    'admin-cat-options'  => ['/admin.php?page=cat_options', true],

    // ── Admin — Photos ────────────────────────────────────────────────────
    'admin-photos-add'   => ['/admin.php?page=photos_add', true],
    'admin-batch'        => ['/admin.php?page=batch_manager', true],

    // ── Admin — Users ─────────────────────────────────────────────────────
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

    // admin-dashboard ('/admin.php'), admin-album ('page=album') and
    // admin-users ('page=user_list') are deliberately NOT covered — see the
    // class-level docblock for why each renders unfreezable wall-clock-
    // relative content.
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
