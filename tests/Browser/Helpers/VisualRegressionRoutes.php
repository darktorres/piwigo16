<?php

declare(strict_types=1);

/**
 * Shared route list for VisualRegressionTest.php (screenshot baselines) and
 * GoldenHtmlSnapshotTest.php (raw-HTML baselines for the P31 Smarty->Latte
 * migration's diff-and-classify verification). One literal array so the two
 * checks can never drift apart on which routes/auth requirements they cover.
 *
 * @return array<string, array{0: string, 1: bool}>
 */
return [
    // ── Gallery (anonymous) ──────────────────────────────────────────────
    'gallery-home' => ['/index.php', false],
    'identification' => ['/identification.php', false],
    'register' => ['/register.php', false],
    'password' => ['/password.php', false],
    'about' => ['/about.php', false],
    'tags' => ['/tags.php', false],
    'notification' => ['/notification.php', false],
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
