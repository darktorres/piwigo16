<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Real, mutation-verified interactive coverage of `vendor/widgets/cluetip.ts`
 * (P49-B) on its one real "auto"-positioned call site,
 * `languages/new.ts`. `LanguagesNewPageRendererTest.php`'s own coverage
 * only ever proves cluetip's shared `#cluetip` element gets created on
 * load -- none of this was ever behaviorally tested before, jQuery-based
 * or not: the actual hover-activate/position/content/deactivate cycle.
 *
 * A synthetic dispatched `mouseenter`/`mouseleave` (not Pest Browser's own
 * `->hover()`) is used so the exact `pageX`/`pageY` at activation is
 * known, letting the expected tip position be computed from the *same*
 * real geometry the port itself reads, rather than guessed. The viewport
 * is fixed first so that computation's "auto" branch choice (right of
 * the link vs. left, due to overflow) stays deterministic across runs.
 */
function cluetipExpectedPosition(): string
{
    return <<<'JS'
    (() => {
        const link = document.querySelectorAll('.cluetip')[0];
        const rect = link.getBoundingClientRect();
        const linkTop = rect.top + window.scrollY;
        const linkLeft = rect.left + window.scrollX;
        // Matches `innerWidth()`'s own real measurement source
        // (`dom.ts`'s `measureBox()`, `offsetWidth`-based) -- NOT
        // `clientWidth`, which is always 0 for a plain `inline` element
        // like this `<a>` (confirmed live: `clientWidth: 0, offsetWidth:
        // 83` for the same real link) -- `clientWidth` looked plausible
        // at a glance but was flat wrong for this element's `display`
        // and produced a provably wrong expected position here first.
        const linkWidth = link.offsetWidth;
        const mouseX = rect.left + rect.width / 2;
        const mouseY = rect.top + rect.height / 2;

        const winWidth = document.documentElement.clientWidth;
        const winHeight = document.documentElement.clientHeight;
        const sTop = window.scrollY;
        const baseline = sTop + winHeight;
        const topOffset = 15, leftOffset = 15, dropShadowSteps = 6;
        const tipWidth = 300 + dropShadowSteps;

        let posX = (linkWidth > linkLeft && linkLeft > tipWidth) ||
            linkLeft + linkWidth + tipWidth + leftOffset > winWidth
            ? linkLeft - tipWidth - leftOffset
            : linkWidth + linkLeft + leftOffset;
        if (linkWidth + tipWidth > winWidth) {
            posX = mouseX + 20 + tipWidth > winWidth
                ? (mouseX - tipWidth - leftOffset >= 0 ? mouseX - tipWidth - leftOffset : mouseX - tipWidth / 2)
                : mouseX + leftOffset;
        }

        const ev = new MouseEvent('mouseenter', { bubbles: true, cancelable: true });
        Object.defineProperty(ev, 'pageX', { value: mouseX });
        Object.defineProperty(ev, 'pageY', { value: mouseY });
        link.dispatchEvent(ev);

        return new Promise((resolve) => setTimeout(() => {
            const tip = document.getElementById('cluetip');
            const tipHeight = tip.offsetHeight;
            const insufficientX = posX < mouseX && Math.max(posX, 0) + tipWidth > mouseX;
            let direction = '', tipY;
            if (insufficientX) {
                direction = linkTop + tipHeight + topOffset > baseline && mouseY - sTop > tipHeight + topOffset ? 'top' : 'bottom';
                tipY = direction === 'top' ? mouseY - tipHeight - topOffset : mouseY + topOffset;
            } else if (linkTop + tipHeight + topOffset > baseline) {
                tipY = tipHeight >= winHeight ? sTop : baseline - tipHeight - topOffset;
            } else if (getComputedStyle(link).display === 'block') {
                tipY = mouseY - topOffset;
            } else {
                tipY = linkTop - dropShadowSteps;
            }
            if (direction === '') {
                direction = posX < linkLeft ? 'left' : 'right';
            }

            resolve(JSON.stringify({
                expectedLeft: posX,
                expectedTop: tipY,
                expectedDirection: direction,
                actualLeft: parseFloat(tip.style.left),
                actualTop: parseFloat(tip.style.top),
                actualClassName: tip.className,
                titleHtml: document.querySelector('.cluetip-title').innerHTML,
                innerHtml: document.querySelector('.cluetip-inner').innerHTML,
                visible: tip.style.visibility,
                nativeTitleDuringHover: link.getAttribute('title'),
            }));
        }, 30));
    })()
    JS;
}

it('shows a positioned tooltip with the split title/body on hover, and restores on mouseleave', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=languages&tab=new');
    $page->resize(1280, 800);

    $originalTitle = H::scriptString($page, "document.querySelectorAll('.cluetip')[0].getAttribute('title')");
    expect($originalTitle)
        ->toContain('|');
    [$expectedTitle, $expectedBodyFirstPart] = explode('|', $originalTitle, 2);

    $result = H::scriptJson($page, cluetipExpectedPosition());
    foreach (['visible', 'nativeTitleDuringHover', 'titleHtml', 'innerHtml', 'actualClassName', 'expectedDirection'] as $field) {
        if (! is_string($result[$field] ?? null)) {
            throw new RuntimeException("unexpected non-string '{$field}': " . var_export($result, true));
        }
    }
    foreach (['actualLeft', 'actualTop', 'expectedLeft', 'expectedTop'] as $field) {
        if (! is_numeric($result[$field] ?? null)) {
            throw new RuntimeException("unexpected non-numeric '{$field}': " . var_export($result, true));
        }
    }

    expect($result['visible'])->toBe('visible');
    expect($result['nativeTitleDuringHover'])->toBe('');
    expect($result['titleHtml'])->toBe($expectedTitle);
    // The raw attribute text carries a literal, self-closed `<br />` (from
    // `nl2br`); once parsed into real DOM nodes via `.innerHTML =` and
    // read back, the browser normalizes it to `<br>` -- normalize the
    // same way here rather than asserting on markup the DOM never keeps.
    expect($result['innerHtml'])->toContain(str_replace(['<br />', '<br/>'], '<br>', $expectedBodyFirstPart));
    // Rounded to whole px: the two sides take their own
    // `getBoundingClientRect()` reading a few JS ticks apart (this
    // script's own vs. cluetip.ts's own, inside the dispatched
    // `mouseenter` handler), and sub-pixel layout can jitter by a
    // fraction of a px between two such calls even with nothing else
    // changing -- a real position bug would be off by whole pixels at
    // minimum, not a fraction of one.
    expect(round((float) $result['actualLeft']))->toBe(round((float) $result['expectedLeft']));
    expect(round((float) $result['actualTop']))->toBe(round((float) $result['expectedTop']));
    expect($result['actualClassName'])->toBe(
        "cluetip ui-widget ui-widget-content ui-cluetip clue-{$result['expectedDirection']}-default cluetip-default"
    );

    // Move off the link -- the real 50ms delayed close should have fired
    // by the time this next check runs, and the original raw title
    // (with its "|" delimiter and any embedded `<br>` markup) restored
    // verbatim. A synthetic dispatch (not Pest Browser's own `->hover()`
    // elsewhere) is used because the now-visible `#cluetip` tooltip
    // itself can sit on top of other real page chrome depending on
    // where this particular link falls, which would make a real,
    // actionability-checked Playwright hover time out trying to find an
    // unobstructed point to move the mouse to.
    $page->script(
        "document.querySelectorAll('.cluetip')[0].dispatchEvent(new MouseEvent('mouseleave', {bubbles: true}))"
    );
    usleep(150_000);
    $page->assertMissing('#cluetip');
    expect(H::scriptString($page, "document.querySelectorAll('.cluetip')[0].getAttribute('title')"))
        ->toBe($originalTitle);

    $page->assertNoJavaScriptErrors();
});

it('cancels a pending delayed close when re-hovered before it fires', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=languages&tab=new');
    $page->resize(1280, 800);

    $stillVisible = H::scriptBool($page, <<<'JS'
    (() => {
        const link = document.querySelectorAll('.cluetip')[0];
        function dispatch(type) {
            const ev = new MouseEvent(type, { bubbles: true, cancelable: true });
            Object.defineProperty(ev, 'pageX', { value: 10 });
            Object.defineProperty(ev, 'pageY', { value: 10 });
            link.dispatchEvent(ev);
        }
        dispatch('mouseenter');
        return new Promise((resolve) => setTimeout(() => {
            dispatch('mouseleave');
            // Re-enter well inside the real 50ms delayed-close window.
            setTimeout(() => {
                dispatch('mouseenter');
                setTimeout(() => {
                    resolve(document.getElementById('cluetip').style.visibility === 'visible');
                }, 80);
            }, 20);
        }, 30));
    })()
    JS);

    expect($stillVisible)
        ->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
