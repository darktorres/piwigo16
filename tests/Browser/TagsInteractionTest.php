<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/tags.ts.
 *
 * `$.confirm()`/`$.alert()` stay jQuery (jquery-confirm, P49-B group 5).
 * `$.cookie()` converted to `cookie()`/`setCookie()`
 * (`themes/default/js/vendor/cookie.ts`) in P49-B group 2.
 *
 * `TemporaryState` (themes/admin/default/js/common.ts) converted off
 * jQuery together with this file -- it wraps no library of its own, and
 * this file's add-tag flow is its only currently-converted real caller
 * (users/group_list.ts's own 8 call sites keep working via a same-commit
 * touch-up: they now pass `document.querySelectorAll(...)` in place of
 * `$(...)`, with the rest of that still-unconverted file unchanged).
 *
 * `HTMLFormElement.submit()` deliberately does not fire a "submit" event
 * (unlike `.click()`/`.focus()`), so jQuery's own `.trigger("submit")`/
 * bare `.submit()` shorthand would never reach a native "submit"
 * listener. `#add-tag .icon-validate`'s click handler uses
 * `form.requestSubmit()` instead, which does -- this test's first
 * assertion (the tag actually gets created by clicking the checkmark
 * icon, not by pressing Enter) is what catches a regression there.
 *
 * The "Add a tag" click handler stays bound to `.add-tag-container`,
 * which the template's own CSS keeps `display: none` until `.input-mode`
 * is added -- looks unreachable, but a real click never lands there
 * directly. `.add-tag-container`'s own parent, `.add-tag-label`, is a
 * <label> whose implicit associated control is `#add-tag-input` (the
 * first labelable descendant); clicking the label makes the browser
 * dispatch its own activation click at that input, which bubbles up
 * through `.add-tag-container` same as a real click would. This test
 * clicks `.add-tag-label`, matching how a real user reaches this button
 * (a direct click at `.add-tag-container` itself has no hit-testable
 * point to click at all, confirmed live -- not how anyone would trigger
 * this either).
 *
 * Every `[data-id=...]` attribute selector in this file was rewritten
 * quoted (`[data-id="123"]`) -- unquoted, a tag id that is all digits is
 * a syntax error under native querySelectorAll (Sizzle tolerated it).
 */
it('creates a tag via the checkmark button, then deletes it via the dropdown', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=tags');

    $tagName = 'Tags Interaction ' . uniqid();

    $page->click('.add-tag-label');
    $page->fill('#add-tag-input', $tagName);
    // The checkmark icon, not Enter -- exercises requestSubmit(), not a
    // real native form submission.
    $page->click('#add-tag .icon-validate');

    $timeoutMs = 10000;
    $encodedName = json_encode($tagName, JSON_THROW_ON_ERROR);
    $page->script(<<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + {$timeoutMs};
            const wanted = {$encodedName};
            const check = () => {
                const box = Array.from(document.querySelectorAll('.tag-box')).find(
                    (el) => el.querySelector('.tag-name')?.textContent?.trim() === wanted
                );
                if (box !== undefined) return resolve(true);
                if (Date.now() > deadline) return reject(new Error('tag never appeared'));
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    $tagId = $page->script(<<<JS
        Array.from(document.querySelectorAll('.tag-box')).find(
            (el) => el.querySelector('.tag-name')?.textContent?.trim() === {$encodedName}
        )?.getAttribute('data-id')
        JS);
    expect($tagId)
        ->not->toBeNull();
    if (! is_string($tagId)) {
        throw new RuntimeException('expected a string tagId, got: ' . var_export($tagId, true));
    }

    // The add-tag <form> is left out of input-mode, and the loading spinner
    // TemporaryState swapped in is reverted back to the plus icon -- both
    // reverse() undoing what changeHTML()/changeAttribute()/removeClass()
    // did going in.
    expect($page->script("document.getElementById('add-tag').classList.contains('input-mode')"))
        ->toBeFalse();
    expect($page->script("document.querySelector('#add-tag .icon-validate').classList.contains('icon-plus')"))
        ->toBeTrue();
    expect($page->script("document.querySelector('#add-tag .icon-validate').getAttribute('style')"))
        ->not->toBe('pointer-event:none');

    $page->assertPresent('.tag-box[data-id="' . $tagId . '"]');

    // Delete it via the dropdown -- exercises the quoted [data-id="..."]
    // selectors in removeTag()/updateBadge() and the jquery-confirm/alert
    // round trip around a fully-converted DOM read/write.
    $page->script("document.querySelector('.tag-box[data-id=\"{$tagId}\"] .showOptions').click()");
    $page->script("document.querySelector('.tag-box[data-id=\"{$tagId}\"] .dropdown-option.delete').click()");

    $page->script(<<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + {$timeoutMs};
            const check = () => {
                if (document.querySelector('.jconfirm .btn-red') !== null) return resolve(true);
                if (Date.now() > deadline) return reject(new Error('confirm dialog never appeared'));
                setTimeout(check, 100);
            };
            check();
        })
        JS);
    $page->script("document.querySelector('.jconfirm .btn-red').click()");

    $page->script(<<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + {$timeoutMs};
            const check = () => {
                if (document.querySelector('.tag-box[data-id="{$tagId}"]') === null) return resolve(true);
                if (Date.now() > deadline) return reject(new Error('tag was never removed'));
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'tags add/delete flow');
});

it('merges two selected tags via the selection panel', function (): void {
    // No prior test, jQuery-based or not, ever drove tags.ts's own
    // selection-mode/merge flow (P51-D) -- mergeGroups()'s own
    // destination id used to come from a bare `val() ?? ""` read
    // (`dest_id`), now `valId()`. Real coverage closes that gap and
    // exercises the real POST api/v1/tags/actions/merge round trip.
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=tags');

    $timeoutMs = 10000;
    $sourceName = 'Tags Merge Source ' . uniqid();
    $destName = 'Tags Merge Dest ' . uniqid();
    $encodedSource = json_encode($sourceName, JSON_THROW_ON_ERROR);
    $encodedDest = json_encode($destName, JSON_THROW_ON_ERROR);

    $waitForTagName = function (string $encodedName) use ($page, $timeoutMs): void {
        $page->script(<<<JS
            new Promise((resolve, reject) => {
                const deadline = Date.now() + {$timeoutMs};
                const check = () => {
                    if (Array.from(document.querySelectorAll('.tag-name')).some((el) => el.textContent.trim() === {$encodedName})) return resolve(true);
                    if (Date.now() > deadline) return reject(new Error('tag never appeared: ' + {$encodedName}));
                    setTimeout(check, 100);
                };
                check();
            })
            JS);
    };

    $page->click('.add-tag-label');
    $page->fill('#add-tag-input', $sourceName);
    $page->click('#add-tag .icon-validate');
    $waitForTagName($encodedSource);

    $page->click('.add-tag-label');
    $page->fill('#add-tag-input', $destName);
    $page->click('#add-tag .icon-validate');
    $waitForTagName($encodedDest);

    // Enable selection mode, then select both new tags. `#toggleSelectionMode`
    // is a real <input type=checkbox> with zero rendered size (a CSS
    // "switch" toggle) -- its own <label class="switch"> wrapper is the
    // real click target, same reasoning as `.add-tag-label` above.
    $page->click('.selection-mode-group-manager .switch');
    $page->script(<<<JS
        Array.from(document.querySelectorAll('.tag-box')).find(
            (el) => el.querySelector('.tag-name')?.textContent?.trim() === {$encodedSource}
        ).click()
        JS);
    $page->script(<<<JS
        Array.from(document.querySelectorAll('.tag-box')).find(
            (el) => el.querySelector('.tag-name')?.textContent?.trim() === {$encodedDest}
        ).click()
        JS);

    $page->click('#MergeSelectionMode');
    $page->script(<<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + {$timeoutMs};
            const check = () => {
                if (document.getElementById('MergeOptionsChoices').options.length >= 2) return resolve(true);
                if (Date.now() > deadline) return reject(new Error('merge options panel never populated'));
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    // Pick the destination tag in #MergeOptionsChoices by its own visible
    // option text (mergeGroups() reads this <select>'s value via valId()).
    $page->script(<<<JS
        (() => {
            const select = document.getElementById('MergeOptionsChoices');
            const option = Array.from(select.options).find((o) => o.textContent.trim() === {$encodedDest});
            select.value = option.value;
        })()
        JS);
    $page->click('.ConfirmMergeButton');

    // mergeGroups()'s own alert() shows a loading-then-summary dialog whose
    // `content` is a function returning ajax()'s own AjaxThenable (same
    // pattern as jconfirm.ts's own documented "content as ajax()" usage) --
    // the real DOM/data updates happen in that ajax call's own `success:`
    // callback, independent of any button click on the dialog itself.
    $page->script(<<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + {$timeoutMs};
            const check = () => {
                const sourceGone = !Array.from(document.querySelectorAll('.tag-name')).some((el) => el.textContent.trim() === {$encodedSource});
                const destStillThere = Array.from(document.querySelectorAll('.tag-name')).some((el) => el.textContent.trim() === {$encodedDest});
                if (sourceGone && destStillThere) return resolve(true);
                if (Date.now() > deadline) return reject(new Error('merge never completed: source still present or destination gone'));
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'tags merge flow');
});

it('persists the tags-per-page cookie across a real page reload', function (): void {
    // tags.ts's own setCookie() (themes/default/js/vendor/cookie.ts,
    // ported off jquery.cookie in P49-B group 2) writes the cookie
    // TagsPageRenderer reads back to pre-select a page-size link on load.
    // TagsPageRendererTest.php's own coverage of that server-side read
    // only ever writes the cookie by hand -- this is the other half: a
    // real click -> setCookie() -> a real second page load, round-tripping
    // through setCookie()'s own encoding. `a[id="200"]`, not `#200`: the
    // per-page links' own ids are bare digits, which querySelectorAll()
    // rejects as an id selector (Sizzle tolerated it) -- the same reason
    // tags.ts's own setPagination() reads back through escapeId().
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=tags');

    $page->script("document.querySelector('a[id=\"200\"]').click()");

    $page = H::navigateOk($page, '/admin.php?page=tags');

    expect($page->script("document.querySelector('a[id=\"200\"]').classList.contains('selected')"))
        ->toBeTrue();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'tags per-page cookie round trip');
});
