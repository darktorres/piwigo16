<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/updates_ext.ts -- 0% JS
 * coverage before this (UpdatesExtPageRendererTest.php only ever asserts
 * the two early-return guard branches for a plain-admin/disabled-install
 * visit). In this environment `getPendingUpdates()` genuinely reaches
 * PEM and reports zero pending updates for every real installed
 * extension, so the page's own `#update_all`/`#ignore_all`/`page-data`
 * (`csrf_token`, `ext_type`) render for real -- `checkFieldsets()`'s own
 * module-load-time call has just hidden `#update_all`/`#ignore_all`
 * (0 real fieldsets -> total=0) and shown `#up_to_date` instead.
 *
 * So this test injects the exact markup `updates_ext.latte` itself emits
 * for a populated fieldset/pluginBox (verified against the template
 * source) directly into `#theAdminPage` after load, re-shows the real
 * (already correctly-bound) `#update_all`/`#ignore_all`, and drives the
 * already-initialized module's window-exposed entry points
 * (`ignoreAll`/`ignoreExtension`/`resetIgnored`) and its real
 * `#update_all` click listener against that synthetic content -- with a
 * real, valid CSRF token, so `ignoreExtension()`/`resetIgnored()`'s full
 * ajax round trip (including their success-callback DOM updates and
 * `checkFieldsets()`) is exercised for real, not just their DOM-filter
 * logic. `updateExtension()`'s own round trip is not: its endpoint
 * actually validates the extension against PEM/the filesystem scan, so
 * a synthetic id 404s -- only its `updateAll()`-driven click-filtering is
 * covered here.
 *
 * `jquery-confirm` (P49-B group 5) stays jQuery; only the DOM work
 * around it converted. `jquery.ajaxmanager` converted to `AjaxQueue`
 * (`themes/default/js/vendor/ajaxQueue.ts`) in P49-B group 2, and
 * `jgrowl` converted to `jGrowl` (`themes/default/js/vendor/jgrowl.ts`)
 * in P49-B group 3.
 */
function updatesExtPluginBoxHtml(string $type, string $id, bool $ignored): string
{
    $hiddenClass = $ignored ? ' u-hidden' : '';
    $dataIgnored = $ignored ? ' data-ignored="true"' : '';

    return <<<HTML
        <div class="pluginBox pluginMiniBox{$hiddenClass}" id="{$type}_{$id}"{$dataIgnored}>
          <div class="pluginContent">
            <div class="pluginName">Test Extension {$id}</div>
            <div class="pluginActions">
              <a href="#" onClick="updateExtension('{$type}', '{$id}', '1');" class="updateExtension pluginActionLevel1"><i class="icon-ok-circled"></i> Install</a>
              <a href="#" onClick="ignoreExtension('{$type}', '{$id}'); return false;" class="ignoreExtension pluginActionLevel2"><i class="icon-block"></i>Ignore this update</a>
            </div>
          </div>
        </div>
        HTML;
}

function updatesExtInjectFixture(Webpage|PendingAwaitablePage|AwaitableWebpage $page): void
{
    $notIgnored = updatesExtPluginBoxHtml('plugins', 'not-ignored', false);
    $ignored = updatesExtPluginBoxHtml('plugins', 'ignored', true);

    // getPendingUpdates() genuinely reaches PEM here and reports no
    // pending updates for any real installed extension, so this page's
    // OWN #update_all/#ignore_all already exist -- checkFieldsets()'s
    // own module-load-time call already hid them (0 real fieldsets ->
    // total=0). Reuse those real elements (with their real click
    // listener, already bound against document.querySelectorAll at
    // import time) rather than creating a second pair, and only inject
    // the fieldset/pluginBox rows those elements' handlers walk.
    //
    // rawWebpage(), not $page directly -- AwaitableWebpage::__call() wraps
    // every call (script() included) in a retry loop meant for polling
    // assertions, and a non-idempotent DOM injection re-run by that retry
    // silently doubles every injected element (BrowserTestHelpers::
    // navigateOk()'s own docblock records the same gotcha for navigate()/
    // content()).
    H::rawWebpage($page)->script(<<<JS
        (() => {
            const root = document.getElementById('theAdminPage');
            root.insertAdjacentHTML('beforeend', `
                <fieldset id="plugins" class="pluginContainer pluginUpdateContainer line-form" data-type="plugins">
                    {$notIgnored}
                    {$ignored}
                </fieldset>
            `);
            document.getElementById('update_all').style.display = '';
            document.getElementById('ignore_all').style.display = '';
            window.__updatesExtClicks = [];
            document.querySelectorAll('.updateExtension, .ignoreExtension').forEach((el) => {
                el.addEventListener('click', () => {
                    window.__updatesExtClicks.push(el.closest('.pluginBox').id);
                });
            });
        })();
        JS);
}

it('clicks every .updateExtension link on "Update All", including one inside an ignored (u-hidden) pluginBox', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=updates&tab=ext');

    updatesExtInjectFixture($page);

    $page->click('#update_all');
    $page->assertPresent('.jconfirm');

    $page->click('.jconfirm button.btn-red');

    $clicked = H::scriptArray(
        $page,
        "window.__updatesExtClicks.filter((id) => id.startsWith('plugins_'))"
    );
    // updateAll()'s filter is `el.closest('div')` -- the immediate
    // `.pluginActions` wrapper, not the `.pluginBox` -- and that div has
    // no explicit CSS `display` rule of its own, so it always computes to
    // the browser default "block" for a <div>, regardless of whether an
    // ANCESTOR (`.pluginBox.u-hidden`) is hidden. So both links fire,
    // faithfully matching the original `jQuery(this).parents("div")
    // .css("display")`, which has the identical "own element only" gap.
    // Inherited, not introduced by this conversion -- not fixed, since
    // P49 is translation-only.
    expect($clicked)
        ->toHaveCount(2)
        ->toContain('plugins_not-ignored')
        ->toContain('plugins_ignored');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'updates_ext update-all confirm dialog');
});

it('does not click anything when the "Update All" confirm dialog is cancelled', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=updates&tab=ext');

    updatesExtInjectFixture($page);

    $page->click('#update_all');
    $page->assertPresent('.jconfirm');

    $page->click('.jconfirm button:not(.btn-red)');

    $clicked = H::scriptArray($page, 'window.__updatesExtClicks');
    expect($clicked)
        ->toBe([]);

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'updates_ext update-all confirm dialog cancel');
});

it('clicks every .ignoreExtension link via ignoreAll(), same "always block" quirk', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=updates&tab=ext');

    updatesExtInjectFixture($page);

    $page->click('#ignore_all');

    $clicked = H::scriptArray(
        $page,
        "window.__updatesExtClicks.filter((id) => id.startsWith('plugins_'))"
    );
    expect($clicked)
        ->toHaveCount(2)
        ->toContain('plugins_not-ignored')
        ->toContain('plugins_ignored');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'updates_ext ignore-all');
});

it('ignoreExtension() hides the item, marks it ignored, reveals reset-ignore, and hides an emptied fieldset', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=updates&tab=ext');

    updatesExtInjectFixture($page);

    $page->click('#plugins_not-ignored .ignoreExtension');

    $decoded = H::scriptJson($page, <<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                const box = document.getElementById('plugins_not-ignored');
                if (box.getAttribute('data-ignored') === 'true') {
                    return resolve(JSON.stringify({
                        boxVisible: box.offsetParent !== null,
                        resetIgnoreVisible: document.getElementById('reset_ignore').offsetParent !== null,
                        resetIgnoreValueProp: document.getElementById('reset_ignore').value,
                        fieldsetVisible: document.getElementById('plugins').offsetParent !== null,
                        upToDateVisible: document.getElementById('up_to_date').offsetParent !== null,
                    }));
                }
                if (Date.now() > deadline) {
                    return reject(new Error('plugins_not-ignored was never marked ignored'));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);
    if (
        ! is_bool($decoded['boxVisible'] ?? null)
        || ! is_bool($decoded['resetIgnoreVisible'] ?? null)
        || ! is_string($decoded['resetIgnoreValueProp'] ?? null)
        || ! is_bool($decoded['fieldsetVisible'] ?? null)
        || ! is_bool($decoded['upToDateVisible'] ?? null)
    ) {
        throw new RuntimeException('unexpected state shape: ' . var_export($decoded, true));
    }
    $state = $decoded;

    expect($state['boxVisible'])->toBeFalse();
    expect($state['resetIgnoreVisible'])->toBeTrue();
    // checkFieldsets()'s count-message write is `setVal(#reset_ignore, ...)`
    // -- `.val()`'s original jQuery equivalent -- on a plain `<div>`, which
    // has no `value` DOM attribute Sizzle/jQuery hooks into; it becomes an
    // inert JS property, never the visible text. Pre-existing in the
    // original jQuery source (`jQuery("#reset_ignore").val(...)`) and
    // faithfully reproduced by setVal(), not a regression this conversion
    // introduces or is in scope to fix -- asserted on the property setVal()
    // actually writes, not the (unaffected) rendered text.
    expect($state['resetIgnoreValueProp'])->toContain('(2)');
    // Both plugins pluginBoxes are now ignored (the fixture's own
    // "ignored" one, plus this click) -- checkFieldsets() counts 0
    // non-ignored rows for the "plugins" fieldset and hides it.
    expect($state['fieldsetVisible'])->toBeFalse();
    expect($state['upToDateVisible'])->toBeTrue();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'updates_ext ignoreExtension');
});

it('resetIgnored() (via #reset_ignore) restores every hidden item and clears the ignored state', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=updates&tab=ext');

    updatesExtInjectFixture($page);

    $page->click('#plugins_not-ignored .ignoreExtension');
    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                if (document.getElementById('plugins_not-ignored').getAttribute('data-ignored') === 'true') {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    return reject(new Error('setup: plugins_not-ignored was never marked ignored'));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    $page->click('#reset_ignore');

    $decoded = H::scriptJson($page, <<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                const notIgnored = document.getElementById('plugins_not-ignored');
                const ignored = document.getElementById('plugins_ignored');
                if (notIgnored.getAttribute('data-ignored') === 'false') {
                    return resolve(JSON.stringify({
                        notIgnoredVisible: notIgnored.offsetParent !== null,
                        ignoredBoxVisible: ignored.offsetParent !== null,
                        ignoredDataIgnored: ignored.getAttribute('data-ignored'),
                        fieldsetVisible: document.getElementById('plugins').offsetParent !== null,
                        updateAllVisible: document.getElementById('update_all').offsetParent !== null,
                        ignoreAllVisible: document.getElementById('ignore_all').offsetParent !== null,
                        upToDateVisible: document.getElementById('up_to_date').offsetParent !== null,
                        resetIgnoreVisible: document.getElementById('reset_ignore').offsetParent !== null,
                    }));
                }
                if (Date.now() > deadline) {
                    return reject(new Error('plugins_not-ignored was never reset'));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);
    if (
        ! is_bool($decoded['notIgnoredVisible'] ?? null)
        || ! is_bool($decoded['ignoredBoxVisible'] ?? null)
        || ! is_string($decoded['ignoredDataIgnored'] ?? null)
        || ! is_bool($decoded['fieldsetVisible'] ?? null)
        || ! is_bool($decoded['updateAllVisible'] ?? null)
        || ! is_bool($decoded['ignoreAllVisible'] ?? null)
        || ! is_bool($decoded['upToDateVisible'] ?? null)
        || ! is_bool($decoded['resetIgnoreVisible'] ?? null)
    ) {
        throw new RuntimeException('unexpected state shape: ' . var_export($decoded, true));
    }
    $state = $decoded;

    expect($state['notIgnoredVisible'])->toBeTrue();
    expect($state['ignoredBoxVisible'])->toBeTrue();
    expect($state['ignoredDataIgnored'])->toBe('false');
    expect($state['fieldsetVisible'])->toBeTrue();
    expect($state['updateAllVisible'])->toBeTrue();
    expect($state['ignoreAllVisible'])->toBeTrue();
    expect($state['upToDateVisible'])->toBeFalse();
    expect($state['resetIgnoreVisible'])->toBeFalse();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'updates_ext resetIgnored');
});

it('shows a real, dismissible jGrowl error toast when updateExtension() 404s', function (): void {
    // No existing test exercises jgrowl.ts's own rendering at all (its
    // predecessor, jquery.jgrowl, was never asserted against either --
    // grep confirms it) -- a real gap this closes, not just a
    // regression check. `type: 'bogus-type'` doesn't match the route's
    // own `{type}` pattern at all, so this 404s at the router, before
    // ExtensionUpdateController ever runs -- deliberately not the
    // focus of a test about jGrowl's own behavior, and confirmed live
    // rather than assumed (the message asserted below is the router's
    // own real text, not a guess).
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=updates&tab=ext');

    $page->script("updateExtension('bogus-type', 'x', '1')");

    $decoded = H::scriptJson($page, <<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 3000;
            const check = () => {
                const toast = document.querySelector('#jGrowl .jGrowl-notification.error');
                if (toast !== null) {
                    return resolve(JSON.stringify({
                        classList: Array.from(toast.classList),
                        header: toast.querySelector('.jGrowl-header').textContent,
                        message: toast.querySelector('.jGrowl-message').textContent,
                        closerCount: document.querySelectorAll('#jGrowl .jGrowl-closer').length,
                    }));
                }
                if (Date.now() > deadline) {
                    return reject(new Error('no #jGrowl .jGrowl-notification.error ever appeared'));
                }
                setTimeout(check, 50);
            };
            check();
        })
        JS);
    if (
        ! is_array($decoded['classList'] ?? null)
        || ! is_string($decoded['header'] ?? null)
        || ! is_string($decoded['message'] ?? null)
        || ! is_int($decoded['closerCount'] ?? null)
    ) {
        throw new RuntimeException('unexpected toast shape: ' . var_export($decoded, true));
    }
    $state = $decoded;

    expect($state['classList'])->toContain('jGrowl-notification', 'ui-state-highlight', 'ui-corner-all', 'error');
    expect($state['header'])->toBe('ERROR');
    expect($state['message'])->toBe('No resource exists at /api/v1/extensions/bogus-type/x/actions/update.');
    // Only one notification is ever showing here -- the "close all"
    // button only renders once a second one joins it.
    expect($state['closerCount'])->toBe(0);

    // `sticky: true` on the error path: still there well past the
    // default 3000ms `life` a non-sticky toast would have expired at
    // (jgrowl.ts's own default, since the error path never sets `life`
    // explicitly) -- 4500ms clears life (~3000ms from the fade-in
    // completing, itself ~250-650ms after the call) plus the ~400ms
    // close-fade a non-sticky toast would already be mid-way or done
    // with by then, confirmed empirically (mutation-tested: forcing
    // `sticky: false` in jgrowl.ts made this assertion fail, restored
    // after).
    $page->script('new Promise((resolve) => setTimeout(resolve, 4500))');
    expect($page->script("document.querySelectorAll('#jGrowl .jGrowl-notification.error').length"))
        ->toBe(1);

    $page->click('#jGrowl .jGrowl-close');
    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 3000;
            const check = () => {
                if (document.querySelectorAll('#jGrowl .jGrowl-notification.error').length === 0) {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    return reject(new Error('toast never closed after clicking .jGrowl-close'));
                }
                setTimeout(check, 50);
            };
            check();
        })
        JS);

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'updates_ext jGrowl error toast');
});
