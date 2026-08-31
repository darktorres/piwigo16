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
 * (group_list.ts's own 8 call sites keep working via a same-commit
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
