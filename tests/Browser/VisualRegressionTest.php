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
 *   - admin-photo-editor and the admin dashboard ('/admin.php') ARE
 *     excluded — both render server-computed wall-clock-relative content
 *     with no available freeze point this phase: the photo editor shows
 *     "posted N hours/days ago" (time_since(), computed from real time()
 *     at render time), and the dashboard's "Activity peak" widget draws
 *     week-of-year labels into a Chart.js canvas from the current date.
 *     Neither is template data that can be pinned the way the hit counter
 *     can; a real fix needs a mockable clock (later phase — P7-P12 kernel
 *     work introduces one). Documented exclusion, not a laundered diff.
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

    // admin-dashboard ('/admin.php') is deliberately NOT covered: its
    // "Activity peak in the last weeks" widget renders week-of-year labels
    // computed from the real current date (Chart.js canvas, not text —
    // can't be frozen via template data), so it never produces a stable
    // baseline. Excluded, not tolerance-widened.
];

foreach ($routes as $name => [$path, $needsAuth]) {
    it("{$name} matches its visual baseline", function () use ($path, $needsAuth): void {
        if ($needsAuth) {
            $page = H::loginAsAdmin($this);
            $page = H::navigateOk($page, $path);
        } else {
            $page = H::visitPwg($this, $path);
            H::assertNoServerErrors($page, $path);
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
