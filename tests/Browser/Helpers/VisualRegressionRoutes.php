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
    'calendar-posted' => ['/index.php?/category/1/posted-monthly-list', false],
    // month_calendar.latte is FILE_CHRONOLOGY_VIEW for both the -list and
    // -calendar styles, but which of its three branches runs depends
    // entirely on how many distinct periods the data holds. Every fixture
    // photo shares one date_available (2026-08), so a -posted calendar
    // bails out of the all-years view (one year) and then the year view
    // (one month) and only ever reaches the month grid. The -created
    // routes carry dates spanning 2024-2026 precisely so the other two
    // branches execute (P58, tools/p58).
    //
    // There is deliberately no -posted-calendar route. Each day cell picks
    // its thumbnail through CalendarRepository::findRandomImageForDay(),
    // which really does ORDER BY RAND(), so a cell is only reproducible
    // when exactly one photo falls on that day. Every fixture photo shares
    // 2026-08-01, so a posted month grid re-rolls its thumbnail on every
    // capture; the -created dates put one photo per day cell instead.
    //
    // No date part: buildGlobalCalendar() emits one calendar_bars row per
    // year (2024/2025/2026).
    'calendar-created-calendar' => ['/index.php?/category/1/created-monthly-calendar', false],
    // One date part, and 2024 holds two months, so buildYearCalendar()
    // emits calendar_bars AND buildNavBar(CYEAR) emits
    // chronology_navigation_bars -- the only route reaching that variable.
    'calendar-created-year' => ['/index.php?/category/1/created-monthly-calendar-2024', false],
    // Two date parts: the month grid over real photos (2026-02 holds two),
    // so calDayCellFull renders alongside buildNextPrev()'s links.
    'calendar-created-month' => ['/index.php?/category/1/created-monthly-calendar-2026-2', false],
    'popuphelp' => ['/popuphelp.php?page=maintenance', false],

    // ── Gallery (auth required) ──────────────────────────────────────────
    'favorites' => ['/index.php?/favorites', true],
    'profile' => ['/profile.php', true],

    // ── Admin — Dashboard ───────────────────────────────────────────────────
    'admin-dashboard' => ['/admin.php', true],

    // ── Admin — Albums ────────────────────────────────────────────────────
    'admin-albums' => ['/admin.php?page=albums', true],
    'admin-album' => ['/admin.php?page=album&cat_id=1', true],
    'admin-album-perms' => ['/admin.php?page=album&cat_id=1&tab=permissions', true],
    'admin-album-sort' => ['/admin.php?page=album&cat_id=1&tab=sort_order', true],
    'admin-album-notification' => ['/admin.php?page=album&cat_id=1&tab=notification', true],
    'admin-cat-options' => ['/admin.php?page=cat_options', true],
    'admin-cat-list' => ['/admin.php?page=cat_list', true],

    // ── Admin — Photos ────────────────────────────────────────────────────
    'admin-photos-add' => ['/admin.php?page=photos_add', true],
    'admin-photos-add-applications' => ['/admin.php?page=photos_add&section=applications', true],
    'admin-photos-add-ftp' => ['/admin.php?page=photos_add&section=ftp', true],
    'admin-batch' => ['/admin.php?page=batch_manager', true],
    'admin-batch-unit' => ['/admin.php?page=batch_manager&mode=unit', true],
    'admin-picture-formats' => ['/admin.php?page=picture_formats&image_id=1', true],
    'admin-picture-coi' => ['/admin.php?page=picture_coi&image_id=1', true],
    'admin-rating' => ['/admin.php?page=rating', true],
    'admin-rating-user' => ['/admin.php?page=rating_user', true],
    // f_min_rates defaults to 2 and the renderer drops every user whose
    // rate count is <= that, so the default route above renders an empty
    // table: the fixture's three raters have 2, 2 and 1 rates. This one
    // lowers the threshold so the per-user row loop actually runs -- it is
    // the only thing that exercises $ratings and, through the thumbnail
    // {capture}, $imageUrls (P58, tools/p58).
    'admin-rating-user-rows' => ['/admin.php?page=rating_user&f_min_rates=0', true],

    // ── Admin — Users ─────────────────────────────────────────────────────
    'admin-users' => ['/admin.php?page=user_list', true],
    'admin-groups' => ['/admin.php?page=group_list', true],
    'admin-group-perm' => ['/admin.php?page=group_perm&group_id=1', true],
    'admin-user-perm' => ['/admin.php?page=user_perm&user_id=1', true],
    'admin-user-activity' => ['/admin.php?page=user_activity', true],
    'admin-notification-by-mail' => ['/admin.php?page=notification_by_mail', true],

    // ── Admin — Configuration / Tools ─────────────────────────────────────
    'admin-config' => ['/admin.php?page=configuration', true],
    'admin-config-display' => ['/admin.php?page=configuration&section=display', true],
    'admin-config-sizes' => ['/admin.php?page=configuration&section=sizes', true],
    'admin-config-watermark' => ['/admin.php?page=configuration&section=watermark', true],
    'admin-config-comments' => ['/admin.php?page=configuration&section=comments', true],
    'admin-config-search' => ['/admin.php?page=configuration&section=search', true],
    'admin-maintenance' => ['/admin.php?page=maintenance', true],
    'admin-history' => ['/admin.php?page=history', true],
    'admin-tags' => ['/admin.php?page=tags', true],
    'admin-comments' => ['/admin.php?page=comments', true],
    'admin-permalinks' => ['/admin.php?page=permalinks', true],
    'admin-menubar' => ['/admin.php?page=menubar', true],
    'admin-site-manager' => ['/admin.php?page=site_manager', true],
    'admin-site-update' => ['/admin.php?page=site_update&site=1', true],
    'admin-stats' => ['/admin.php?page=stats', true],
    'admin-help' => ['/admin.php?page=help', true],
    'admin-popuphelp' => ['/admin/popuphelp.php?page=extend_for_templates', true],

    // ── Admin — Themes / Languages / Plugins / Updates ─────────────────────
    // Themes/languages/plugins each expose three tabs -- installed, update,
    // new (CoreTabs.php). Each `*-installed` name here used to point at
    // `tab=update`, so `tab=installed` -- the default tab, the one reached by
    // a bare `page=plugins` -- had no golden or visual coverage at all, and a
    // change to it could not fail either suite. The names now match the tab
    // they fetch; keep it that way.
    'admin-themes-installed' => ['/admin.php?page=themes&tab=installed', true],
    'admin-themes-update' => ['/admin.php?page=themes&tab=update', true],
    'admin-themes-new' => ['/admin.php?page=themes&tab=new', true],
    'admin-themes-standard-pages' => ['/admin.php?page=themes&tab=standard_pages', true],
    'admin-languages-installed' => ['/admin.php?page=languages&tab=installed', true],
    'admin-languages-update' => ['/admin.php?page=languages&tab=update', true],
    'admin-languages-new' => ['/admin.php?page=languages&tab=new', true],
    'admin-plugins-installed' => ['/admin.php?page=plugins&tab=installed', true],
    'admin-plugins-update' => ['/admin.php?page=plugins&tab=update', true],
    'admin-plugins-new' => ['/admin.php?page=plugins&tab=new', true],
    'admin-updates-pwg' => ['/admin.php?page=updates&tab=pwg', true],
    'admin-updates-ext' => ['/admin.php?page=updates&tab=ext', true],
];
