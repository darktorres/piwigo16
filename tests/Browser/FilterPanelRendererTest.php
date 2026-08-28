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

/**
 * The dimension filter's four ratio-preset buttons (Portrait / Square /
 * Landscape / Panorama) each render only when at least one distinct photo
 * ratio falls in that bucket -- `BatchManagerSubController::
 * computeDimensionOptions()` leaves the other categories null and the
 * template's own `n:if` drops them. Every fixture photo is 200x150, one
 * single ratio of 1.33, so only Landscape has ever rendered: three of the
 * four branches, and with them every `ratio_*` read in the template, were
 * unreachable by any request. Four temporary dimensions produce one photo
 * per bucket.
 *
 * `floor($w / $h * 100) / 100` is the ratio the producer computes, so the
 * expected values below are 150/200 -> 0.75, 200/200 -> 1, 200/150 -> 1.33
 * and 400/150 -> 2.66, and `implode(',')` renders the second as `1`, not
 * `1.00`.
 */
it('renders all four dimension ratio presets when every ratio bucket is populated', function (): void {
    $db = H::connect();

    $original = [];
    foreach ([1, 2, 3, 4, 5] as $imageId) {
        $row = H::fetchAssocOrFail($db, 'SELECT width, height FROM images WHERE id = ' . $imageId);
        $original[$imageId] = [(int) $row['width'], (int) $row['height']];
    }

    // portrait 0.75, square 1, landscape 1.33 (already), panorama 2.66.
    H::dbQuery($db, 'UPDATE images SET width = 150, height = 200 WHERE id = 1');
    H::dbQuery($db, 'UPDATE images SET width = 200, height = 200 WHERE id = 2');
    H::dbQuery($db, 'UPDATE images SET width = 400, height = 150 WHERE id = 4');

    try {
        $page = H::asAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=batch_manager');
        $page->assertNoJavaScriptErrors();

        $html = H::rawWebpage($page)->content();

        // Each preset asserted as the whole rendered element, not as loose
        // attribute values: doubleSlider.ts:78 reads these back through
        // `$(this).data("min")` and looks the result up with
        // `options.values.indexOf()`, which only ever matches when the
        // attribute holds exactly the string the ratio list carries. This
        // is the serialized DOM, where attribute whitespace is preserved
        // verbatim but source line breaks between attributes are not --
        // so a stray newline *inside* a value survives here and is exactly
        // what this assertion is shaped to catch.
        foreach ([
            ['Portrait', '0.75', '0.75'],
            ['Square', '1', '1'],
            ['Landscape', '1.33', '1.33'],
            ['Panorama', '2.66', '2.66'],
        ] as [$label, $min, $max]) {
            expect($html)->toContain('<a class="slider-choice" data-min="' . $min . '" data-max="' . $max . '">' . $label . '</a>');
        }

        // The bounds the Reset button restores, and the option list the
        // slider is actually built from -- the ratio list is what every
        // data-min above has to be findable in.
        expect($html)
            ->toContain('<a class="slider-choice dimension-cancel" data-min="0.75" data-max="2.66">Reset</a>')
            ->toContain('<span class="slider-info">between 0.75 and 2.66</span>')
            ->toContain('between 150 and 400 pixels')
            ->toContain('between 150 and 200 pixels')
            ->toContain('"ratios":"0.75,1,1.33,2.66"')
            ->toContain('"widths":"150,200,400"')
            ->toContain('"heights":"150,200"');
    } finally {
        foreach ($original as $imageId => [$width, $height]) {
            H::dbQuery($db, sprintf('UPDATE images SET width = %d, height = %d WHERE id = %d', $width, $height, $imageId));
        }

        H::dbClose($db);
    }
});
