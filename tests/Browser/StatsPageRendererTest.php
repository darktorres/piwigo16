<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\StatsPageRenderer (admin.php?page=stats) -- the "history"
 * tabsheet group's stats/charts tab. No dedicated test file existed before
 * this one.
 *
 * Not exercised: the several `count(...) > 1` "multiple years/months of
 * real usage history already summarized" branches (getLast()'s 'year'
 * case, getLastMonths()'s 'all' case) -- these need real, multi-year-old
 * history_summary data, which piwigo_history_summary accumulates only from
 * HistoryService::summarize()'s own aggregation of real piwigo_history
 * rows over actual elapsed time. Fabricating that deterministically here
 * would mean writing directly into piwigo_history_summary, which
 * summarize() itself (called unconditionally at the top of every real
 * render()) could then rewrite or conflict with -- too fragile relative to
 * this one page's narrow benefit, and this table is shared with every
 * other Browser test exercising real page views throughout this whole
 * suite run.
 */
it('renders the stats page as a webmaster', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=stats');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'stats page');
});
