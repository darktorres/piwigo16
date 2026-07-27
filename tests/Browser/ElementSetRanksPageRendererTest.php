<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * ElementSetRanksPageRenderer (admin.php?page=album&cat_id=X&tab=sort_order,
 * the "sort_order" tab of the "album" page) -- 0% coverage before this
 * file.
 *
 * Fixture shape (tests/Fixtures/piwigo-17.0.sql): category 1 "Sample Album"
 * has 3 direct photos (image_id 1/2/3, image_category.rank 1/2/3, ORDER BY
 * `rank` in the renderer's own query) named "Photo 1"/"Photo 2"/"Photo 3".
 * The renderer's own RANK template value is `(++$current_rank) * 10`
 * starting from 1 -- a faithful port of the legacy admin/element_set_ranks.php
 * (confirmed identical, not a rewrite regression), so the FIRST thumbnail
 * gets rank 20, not 10 -- asserted explicitly below since it's a genuinely
 * surprising off-by-one to lock in.
 */
it('renders one ranked thumbnail per photo, in rank order, with the legacy off-by-one multiplier', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=album&cat_id=1&tab=sort_order');
    $page->assertNoJavaScriptErrors();

    expect($page->value('input[name="rank_of_image[1]"]'))->toBe('20');
    expect($page->value('input[name="rank_of_image[2]"]'))->toBe('30');
    expect($page->value('input[name="rank_of_image[3]"]'))->toBe('40');
    $page->assertPresent('img[alt="Photo 1"]');
    $page->assertPresent('img[alt="Photo 2"]');
    $page->assertPresent('img[alt="Photo 3"]');

    // category.image_order is NULL in the fixture -- the renderer's own
    // "elseif ($category->imageOrder !== '')" check treats null as
    // non-empty (null !== ''), so "automatic order" (user_define) is
    // pre-selected instead of "Use the default photo sort order", even
    // though no image_order was ever explicitly set. This is the same
    // (faithfully ported) behaviour as legacy admin/element_set_ranks.php,
    // confirmed by direct comparison -- not a rewrite regression, but a
    // real, surprising-enough-to-lock-in quirk.
    $page->assertRadioSelected('image_order_choice', 'user_define');
    $page->assertRadioNotSelected('image_order_choice', 'default');
});

// Edge case: an album with zero photos skips the whole "Manual order"
// thumbnails block entirely ({if !empty($thumbnails)} in
// element_set_ranks.tpl), a genuinely different branch from the populated
// case above.
it('renders no manual-order thumbnails block for an album with no photos', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Empty Ranks Test Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (!is_array($albumResult) || !isset($albumResult['id']) || !is_numeric($albumResult['id'])) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $pwgToken = H::pwgToken($page);

    $page = H::navigateOk($page, '/admin.php?page=album&cat_id=' . $albumId . '&tab=sort_order');
    $page->assertNoJavaScriptErrors();

    // Note: the "Sort order" fieldset's own 2nd radio choice label is
    // literally "manual order" (lowercase, always rendered) -- a plain
    // assertDontSee('Manual order') would false-positive-match it
    // case-insensitively, so this checks for the *thumbnails* fieldset's own
    // distinguishing elements (its icon class and the <ul> it wraps)
    // instead of the ambiguous shared words.
    $page->assertMissing('.icon-sort-alt-down');
    $page->assertMissing('ul.thumbnails');

    H::wsCall($page, 'pwg.categories.delete', [
        'category_id' => $albumId, 'photo_deletion_mode' => 'no_delete', 'pwg_token' => $pwgToken,
    ]);
});
