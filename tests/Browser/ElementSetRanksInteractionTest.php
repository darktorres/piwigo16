<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/element_set_ranks.ts.
 * ElementSetRanksPageRendererTest.php's own tests only ever assert the
 * page's initial render or POST the form directly -- neither exercises
 * this file's own JS: the image_order_choice radio toggle, or the
 * sortable widget's `update` callback that renumbers rank_of_image and
 * checks the "manual order" radio after a drag.
 *
 * `.sortable(...)` itself stays jQuery (jQuery-UI, P49-B group 4). Its
 * `update` callback is real, converted code -- exercised here not via a
 * simulated mouse drag (jQuery-UI's own mouse-based dragging doesn't
 * respond to Playwright's HTML5-drag-event-based dragTo(), a well-known
 * incompatibility, and this suite has no existing precedent for testing a
 * jQuery-UI sortable drag any other way) but via the widget's own
 * `_trigger('update', ...)` -- the exact call jQuery-UI's real drag-stop
 * handler makes, with `this` bound to the sortable's element the same way
 * (confirmed by reading jquery-ui's own Widget.prototype._trigger, which
 * does `callback.apply(this.element[0], ...)`). This exercises the real,
 * converted callback body; the widget's own drag mechanics are P49-B's to
 * verify once sortable itself ports.
 */
it('toggles image_order_user_define_options based on the selected radio', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=album&cat_id=1&tab=sort_order');

    // Fixture default: category.image_order is NULL, so "automatic order"
    // (user_define) is pre-selected and its options are visible on load.
    $page->assertRadioSelected('image_order_choice', 'user_define');
    expect($page->script(
        "getComputedStyle(document.getElementById('image_order_user_define_options')).display"
    ))->not->toBe('none');

    $page->click('label.font-checkbox:has(input[name=image_order_choice][value=default])');

    expect($page->script(
        "getComputedStyle(document.getElementById('image_order_user_define_options')).display"
    ))->toBe('none');

    $page->click('label.font-checkbox:has(input[name=image_order_choice][value=user_define])');

    expect($page->script(
        "getComputedStyle(document.getElementById('image_order_user_define_options')).display"
    ))->not->toBe('none');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'element set ranks radio toggle');
});

it('renumbers rank_of_image to the new order and selects manual order after a reorder', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=album&cat_id=1&tab=sort_order');

    // Baseline established by ElementSetRanksPageRendererTest.php's own
    // sibling test: category 1's 3 fixture photos start at ranks 20/30/40,
    // in image id order 1/2/3.
    expect($page->value('input[name="rank_of_image[1]"]'))
        ->toBe('20');
    expect($page->value('input[name="rank_of_image[2]"]'))
        ->toBe('30');
    expect($page->value('input[name="rank_of_image[3]"]'))
        ->toBe('40');

    // Move the first <li> to the end of the list -- what a real drag to
    // the back would leave the DOM looking like -- then fire the exact
    // call jQuery-UI's own drag-stop handler makes.
    $newOrder = $page->script(<<<'JS'
        (() => {
            const list = document.querySelector('ul.thumbnails');
            list.appendChild(list.querySelector('li'));
            jQuery(list).data('ui-sortable')._trigger('update', null, {});
            return Array.from(list.querySelectorAll('input[name^=rank_of_image]'))
                .map((input) => [input.name, input.value]);
        })()
        JS);

    expect($newOrder)
        ->toBe([
            ['rank_of_image[2]', '10'],
            ['rank_of_image[3]', '20'],
            ['rank_of_image[1]', '30'],
        ]);

    $page->assertRadioSelected('image_order_choice', 'rank');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'element set ranks reorder');
});
