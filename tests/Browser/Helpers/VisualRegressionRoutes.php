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
    // navigation_bar.latte's *gallery* copy -- a different file from the
    // admin one below, with its own markup (.navigationBar, First/Last
    // spans) -- rendered in no fixture and no VR baseline either.
    // comments.php takes its page size from ?items_number, and only 3 of
    // the 5 fixture comments are validated, so 2 per page is 2 pages. This
    // is also the one call site that includes the template bare, with full
    // parent-scope inheritance, rather than passing `navbar:` explicitly.
    // Same two-route split as the admin pair below -- first page and last
    // page run all four of the template's arms between them: page 1 leaves
    // First/Previous as plain text and links Next/Last, the last page does
    // the reverse.
    'comments-paged-first' => ['/comments.php?items_number=2', false],
    'comments-paged-last' => ['/comments.php?items_number=2&start=2', false],
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
    // navigation_bar.latte rendered nowhere in this table -- no fixture
    // page paginated, so `class="navigationBar"` appeared in none of the
    // 83 golden files and in none of the 75 VR baselines, for either
    // theme's copy of it.
    //
    // Two GET parameters get there without touching a preference or the
    // data. `filter` writes the session filter
    // (BatchManagerRequest::$urlFilterTokens), and the batch manager shows
    // nothing at all without one -- `prefilter-all_photos` resolves
    // through FilterResolver::resolvePrefilter(), whose 'all_photos' arm
    // is guarded on being the only active filter, which it is here.
    // `display` is the unit tab's own per-page count, so 2 over the five
    // fixture photos is three pages. The session write is safe in this
    // table: GoldenHtmlSnapshotTest and VisualRegressionTest each capture
    // a route through its own fresh cookie jar, so no other route sees it.
    //
    // Two routes, first page and last page, which between them run all
    // four of the template's arms: page 1 renders the left arrow
    // `unavailable` and the right one as a link, the last page does the
    // reverse. Both render the .actual span for the current page.
    //
    // Not covered by either, and not reachable here: the `...` elision
    // between non-adjacent page numbers, which createNavigationBar() only
    // emits once there are enough pages to skip. Five photos cannot make
    // one.
    'admin-batch-unit-paged-first' => ['/admin.php?page=batch_manager&mode=unit&filter=prefilter-all_photos&display=2', true],
    'admin-batch-unit-paged-last' => ['/admin.php?page=batch_manager&mode=unit&filter=prefilter-all_photos&display=2&start=4', true],
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
    // The maintenance page has three tabs and only its default one was
    // captured. 'sys' is the one that matters here: it is the sole renderer
    // of maintenance_sys.latte, whose activity-log rows are eleven reads off
    // an untyped bag (P58, tools/p58), and its rows come from the fixture's
    // own activity table.
    //
    // There is deliberately no 'env' route. That tab prints wall-clock
    // timestamps next to its PHP/MySQL rows ("[2026-08-28 21:40:29]"), so
    // every capture differs from the last -- the same reason
    // calendar-posted-calendar was removed. Cover it with an assertion that
    // names what it should contain, not a snapshot.
    'admin-maintenance-sys' => ['/admin.php?page=maintenance&tab=sys', true],
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
