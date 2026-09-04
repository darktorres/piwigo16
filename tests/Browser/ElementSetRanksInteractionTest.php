<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/elementSetRanks.ts.
 * ElementSetRanksPageRendererTest.php's own tests only ever assert the
 * page's initial render or POST the form directly -- neither exercises
 * this file's own JS: the image_order_choice radio toggle, or the
 * sortable widget's `update` callback that renumbers rank_of_image and
 * checks the "manual order" radio after a drag.
 *
 * `.sortable(...)` is a real native port now (P49-C,
 * `vendor/widgets/sortable.ts`) -- its own `pointerdown`/`pointermove`/
 * `pointerup` mechanics respond to plain `page->mouse` events directly
 * (unlike jQuery UI's own mouse-based dragging, which never responded
 * to Playwright's HTML5-drag-event-based `dragTo()`, the reason this
 * test used to simulate the widget's own internal `_trigger('update',
 * ...)` call instead of a real drag). This is a real mouse drag now,
 * closing that gap.
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

    // Real mouse drag: pointerdown on the first <li>, pointermove to
    // land inside the last <li>'s own bounding box (past its own
    // midpoint, so vendor/widgets/sortable.ts's own reorder() places the
    // dragged item after it), pointerup to drop.
    $newOrder = $page->script(<<<'JS'
        (() => {
            const list = document.querySelector('ul.thumbnails');
            const items = Array.from(list.querySelectorAll('li.rank-of-image'));
            const first = items[0];
            const last = items[items.length - 1];
            const firstRect = first.getBoundingClientRect();
            const lastRect = last.getBoundingClientRect();

            first.dispatchEvent(new PointerEvent('pointerdown', {
                clientX: firstRect.left + firstRect.width / 2,
                clientY: firstRect.top + firstRect.height / 2,
                pointerId: 1,
                bubbles: true,
                cancelable: true,
            }));
            document.dispatchEvent(new PointerEvent('pointermove', {
                clientX: lastRect.left + lastRect.width / 2,
                clientY: lastRect.top + lastRect.height / 2,
                pointerId: 1,
                bubbles: true,
            }));
            document.dispatchEvent(new PointerEvent('pointerup', {
                clientX: lastRect.left + lastRect.width / 2,
                clientY: lastRect.top + lastRect.height / 2,
                pointerId: 1,
                bubbles: true,
            }));

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
