<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\BatchManager\FilterPanelRenderer -- the shared filter-panel
 * body both BatchManagerGlobalPageRenderer and BatchManagerUnitPageRenderer
 * render into their own page (admin.php?page=batch_manager). Every other
 * real branch of this class is already exercised incidentally by
 * BatchManagerGlobalPageRendererTest/BatchManagerUnitPageRendererTest/
 * BatchManagerSubControllerTest (any batch_manager request renders this
 * panel) -- this file targets only the one branch nothing else reaches:
 * the NB_NO_MD5SUM template assign when AdminShell's own PageState::
 * noMd5sumNumber counter is non-null (i.e. at least one real photo has no
 * checksum yet).
 */
it('shows the missing-checksum counter when at least one photo has no md5sum', function (): void {
    $db = H::connect();

    // Every fixture image ships a real, non-null md5sum (confirmed via a
    // direct read of tests/Fixtures/piwigo-17.0.sql's own INSERT) -- image
    // 1's is nulled out here to genuinely produce AdminShell's
    // `$nb_no_md5sum > 0` condition (its own no_md5sum-counting block only
    // runs for page_slug 'site_update'/'batch_manager', confirmed by
    // direct read), restored in the finally block below. Count every
    // *other* image missing a checksum first, in case an unrelated
    // fixture-regen run left any -- this asserts the exact expected total
    // rather than assuming 1.
    $before = H::fetchAssocOrFail($db, 'SELECT COUNT(*) AS n FROM images WHERE md5sum IS NULL AND id != 1');
    $expectedCount = (int) $before['n'] + 1;

    $original = H::fetchAssocOrFail($db, 'SELECT md5sum FROM images WHERE id = 1');
    expect($original['md5sum'] ?? null)->not->toBeNull('fixture precondition: image 1 must start with a real md5sum');
    H::dbQuery($db, 'UPDATE images SET md5sum = NULL WHERE id = 1');

    try {
        $page = H::asAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=batch_manager');
        $page->assertNoJavaScriptErrors();

        $html = H::rawWebpage($page)->content();
        expect($html)
            ->toContain('id="md5sum_to_add" data-origin="' . $expectedCount . '"');
    } finally {
        $md5sum = $original['md5sum'];
        H::dbQuery($db, sprintf('UPDATE images SET md5sum = %s WHERE id = 1', $md5sum === null ? 'NULL' : "'" . H::dbEscape($db, (string) $md5sum) . "'"));
        H::dbClose($db);
    }
});
