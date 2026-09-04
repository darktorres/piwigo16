<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/categories/modify.ts -- 0% prior
 * live-interaction coverage (CatModifyPageRendererTest.php only ever
 * asserts the rendered page).
 *
 * `.warnings` (toggled by checkAlbumLock()) is never rendered on this
 * page: it comes from `admin.latte`'s own `n:if="isset($warnings)"`
 * shared-layout gate, and CatModifyPageRenderer never sets $warnings --
 * confirmed by grep, not assumed -- so that specific effect has no
 * observable DOM to assert on here.
 *
 * `.tiptip` (P49-B group 2) stays jQuery; only the DOM work around it
 * converted. A fresh throwaway album is used for every test that mutates
 * state, never the shared fixture's category 1/2.
 */
it('reveals the comment-option dropdown on click and hides it again on an outside click', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Cat Modify Comment Dropdown ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];

    $page = H::navigateOk($page, '/admin.php?page=album&cat_id=' . $albumId . '&tab=properties');

    $isOpen = static fn (Webpage|PendingAwaitablePage|AwaitableWebpage $page): mixed => $page->script(
        "document.querySelector('.comment-option').offsetParent !== null"
    );

    expect($isOpen($page))
        ->toBeFalse();

    $page->click('.toggle-comment-option');

    expect($isOpen($page))
        ->toBeTrue();

    $page->click('.cat-modify-content');

    expect($isOpen($page))
        ->toBeFalse();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'cat_modify comment-option dropdown');
});

it('saves a renamed album name via #cat-properties-save and shows the success message', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Cat Modify Save ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];

    $page = H::navigateOk($page, '/admin.php?page=album&cat_id=' . $albumId . '&tab=properties');

    $newName = 'Renamed ' . uniqid();
    $page->fill('#cat-name', $newName);
    $page->click('#cat-properties-save');

    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                if (document.querySelector('.info-message').offsetParent !== null) {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    return reject(new Error('success message never shown'));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    // save_button_set_loading(false) also runs in the success handler --
    // the spinner icon should be gone and the save button re-enabled.
    $state = H::scriptJson($page, <<<'JS'
        JSON.stringify({
            iconClass: document.querySelector('#cat-properties-save i').className,
            disabled: document.getElementById('cat-properties-save').disabled,
        })
        JS);
    if (! is_string($state['iconClass'] ?? null) || ! is_bool($state['disabled'] ?? null)) {
        throw new RuntimeException('unexpected state shape: ' . var_export($state, true));
    }
    expect($state['iconClass'])
        ->toContain('icon-floppy')
        ->not->toContain('icon-spin6');
    expect($state['disabled'])
        ->toBeFalse();

    $page = H::navigateOk($page, '/admin.php?page=album&cat_id=' . $albumId . '&tab=properties');
    expect($page->value('#cat-name'))
        ->toBe($newName);

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'cat_modify save name');
});

it('locks the album via the switch and persists it through #cat-properties-save', function (): void {
    // `.unlock-album` (a second, separate unlock affordance this file
    // also wires up) has no template call site anywhere in this codebase
    // -- confirmed by grep across every admin template -- so only the
    // switch + save round trip is reachable here; not fixed, since P49 is
    // translation-only and the handler binding zero elements is harmless.
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Cat Modify Lock ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];

    $page = H::navigateOk($page, '/admin.php?page=album&cat_id=' . $albumId . '&tab=properties');

    expect($page->script("document.getElementById('cat-locked').checked"))
        ->toBeFalse();

    // `.switch input` is visually hidden (opacity:0) -- the real click
    // target is the wrapping <label>, same pattern as every other
    // toggle-switch in this campaign (common.ts's font-checkbox,
    // plugins/new.ts's beta-test switch).
    $page->click("label[for='cat-locked']");
    $page->click('#cat-properties-save');

    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                if (document.querySelector('.info-message').offsetParent !== null) {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    return reject(new Error('lock save success message never shown'));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    $page = H::navigateOk($page, '/admin.php?page=album&cat_id=' . $albumId . '&tab=properties');
    expect($page->script("document.getElementById('cat-locked').checked"))
        ->toBeTrue();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'cat_modify lock via switch');
});

it('deletes the album via .deleteAlbum, loading its confirm dialog content from a real ajax call', function (): void {
    // Real, load-bearing behavior of themes/default/js/vendor/widgets/jconfirm.ts
    // (P49-B group 5) that no other Browser test exercises: a `content`
    // function returning this app's own `ajax()` thenable opens the
    // dialog with a loading spinner first, then the *success callback*
    // pushes the real content via `setContent()` -- not a value read off
    // the settled promise itself. A fresh, empty throwaway album means
    // `nbImagesRecursive` is falsy, so only the `#no_delete` radio (never
    // `#force_delete`/`#delete_orphans`) should render once that content
    // lands.
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Cat Modify Delete ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];

    $page = H::navigateOk($page, '/admin.php?page=album&cat_id=' . $albumId . '&tab=properties');

    $page->click('.deleteAlbum');
    $page->assertPresent('.jconfirm');

    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                const radio = document.getElementById('no_delete');
                if (radio !== null) return resolve(true);
                if (Date.now() > deadline) {
                    return reject(new Error('orphan-impact content never loaded'));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    expect($page->script("document.getElementById('no_delete').checked"))
        ->toBeTrue();
    expect($page->script("document.getElementById('force_delete')"))
        ->toBeNull();

    $page->click('.jconfirm button.btn-red');

    $page->script(<<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                if (!window.location.href.includes('cat_id={$albumId}')) {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    return reject(new Error('delete-album redirect never happened'));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    $response = H::listCategoriesAdmin($page);
    $categories = $response['categories'] ?? null;
    if (! is_array($categories)) {
        throw new RuntimeException('listCategoriesAdmin response missing categories: ' . var_export($response, true));
    }
    $ids = array_map(
        static fn (mixed $cat): mixed => is_array($cat) ? ($cat['id'] ?? null) : null,
        $categories,
    );
    expect($ids)
        ->not->toContain($albumId);

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'cat_modify delete album via confirm dialog');
});
