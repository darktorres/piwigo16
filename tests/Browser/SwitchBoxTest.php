<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

// switchbox.ts is the gallery's popup positioner: four of them on a category
// page (sort order, related tags, photo sizes, calendar view), all driven by
// one function that measures the link, measures the popup, writes `left`/
// `top` and toggles it into view.
//
// It is invisible to every snapshot instrument. Golden-html captures the raw
// HTTP body, which contains none of this -- the positioning is written by JS
// on click. Visual regression captures the page at rest, and at rest every
// popup is hidden. So the whole of it -- four jQuery geometry getters, a
// two-type event binding and the display-memory toggle -- has no coverage at
// all unless a test clicks.
//
// That matters more here than for a typical conversion because the popup is
// measured *while it is still `display: none`*, where `offsetWidth` reads 0
// and only the computed-style fallback gives a real number. A translation
// that reached for `offsetWidth` alone would pass every static check and
// then pin every popup to the right-hand edge of the window.

it('positions and toggles a switch box, and closes it again', function (): void {
    $page = H::gotoOk($this, '/index.php?/category/1');

    // The popup starts hidden by the theme's own CSS, not by an inline
    // style -- which is exactly why show/hide has to remember a display
    // value rather than assume `block`.
    $page->assertMissing('#sortOrderBox');

    $page->click('#sortOrderLink');
    $page->assertVisible('#sortOrderBox');

    /** @var array{left: string, top: string, right: float, viewport: int, display: string} $geometry */
    $geometry = $page->script(<<<'JS'
    (() => {
        const box = document.getElementById('sortOrderBox');
        const rect = box.getBoundingClientRect();

        return {
            left: box.style.left,
            top: box.style.top,
            right: rect.right,
            viewport: document.documentElement.clientWidth,
            display: getComputedStyle(box).display,
        };
    })()
    JS);

    // Both coordinates were written, in px, from real measurements -- a
    // dropped unit or a NaN would leave the declaration unset.
    expect($geometry['left'])->toMatch('/^-?\d+(\.\d+)?px$/');
    expect($geometry['top'])->toMatch('/^-?\d+(\.\d+)?px$/');
    expect($geometry['display'])->not->toBe('none');

    // The whole point of the width arithmetic: `Math.min(linkLeft, viewport
    // - popupWidth - 5)` keeps the popup 5px inside the right edge. This
    // popup opens from a toolbar button near that edge, so the clamp is the
    // branch that wins and the inset is directly observable.
    //
    // This assertion is the one that caught the real bug. A hidden element
    // has no box, so the first port measured the popup as zero-width and
    // the clamp degenerated to `viewport - 5`, which is never less than the
    // link's own position -- the popup then sat flush at `right ===
    // viewport`, 0px inset, and rendered narrower and taller for it.
    // Everything else here, and every golden and visual snapshot, passed.
    expect($geometry['viewport'] - $geometry['right'])->toBeGreaterThan(4.0);
    expect($geometry['viewport'] - $geometry['right'])->toBeLessThan(8.0);

    // Clicking the link again toggles it back, and the box must return to
    // hidden rather than to some other display value.
    $page->click('#sortOrderLink');
    $page->assertMissing('#sortOrderBox');

    // The second binding: the box closes on mouseleave as well as on click,
    // a single `on("mouseleave click")` registration in the source.
    $page->click('#sortOrderLink');
    $page->assertVisible('#sortOrderBox');
    $page->script("document.getElementById('sortOrderBox').dispatchEvent(new MouseEvent('mouseleave'))");
    $page->assertMissing('#sortOrderBox');

    $page->assertNoJavaScriptErrors();
});

it('drives every switch box on the page from the same registration queue', function (): void {
    $page = H::gotoOk($this, '/index.php?/category/1');

    // index.ts pushes four link/box pairs onto window.SwitchBox, and
    // picture.ts pushes more; the queue is drained by switchbox.ts itself.
    // Anything still queued rather than bound would leave the popup inert.
    foreach (['sortOrder', 'relatedTags', 'derivativeSwitch'] as $name) {
        $link = $name === 'relatedTags' ? '#cmdRelatedTags' : "#{$name}Link";
        $box = $name === 'relatedTags' ? '#relatedTagsBox' : "#{$name}Box";

        $page->assertMissing($box);
        $page->click($link);
        $page->assertVisible($box);
        $page->click($link);
        $page->assertMissing($box);
    }

    $page->assertNoJavaScriptErrors();
});
