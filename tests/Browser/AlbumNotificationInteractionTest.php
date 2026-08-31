<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/album_notification.ts.
 * AlbumNotificationPageRendererTest.php's own tests all POST directly via
 * H::adminPost(), bypassing this file's own JS entirely -- neither the
 * who_group/who_users panel toggle nor the submit-time recipient guard had
 * live coverage before this.
 *
 * `.actionButtons .errors` (shown/hidden by the submit guard below) matches
 * no element anywhere in album_notification.latte -- confirmed by reading
 * the template. Pre-existing dead code, not something this conversion
 * introduces or fixes: jQuery's own `.show()`/`.hide()` on an empty set is
 * a silent no-op, exactly what the native show()/hide() helpers do too, so
 * the (harmless) behaviour carries over unchanged. Not asserted on here for
 * that reason -- there's nothing to see.
 *
 * `.who_option select` is selectize-initialized (P49-B group 6), but the
 * submit handler only ever *reads* `option:selected` -- a live DOM state
 * query, not an event subscription -- so unlike rating.ts's "change"
 * binding (project_p49_jquery_trigger_native_listener_gap), there is no
 * jQuery-trigger dependency here: reading the option state after a
 * selectize-driven selection is safe over a native conversion.
 */
it('toggles between the group and users recipient panels', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Album Notification Toggle Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];

    $page = H::navigateOk($page, '/admin.php?page=album-' . $albumId . '-notification');

    /** @return array{group: string, users: string} */
    $displays = static function () use ($page): array {
        $result = H::scriptArray($page, <<<'JS'
            ({
                group: getComputedStyle(document.querySelector('.who_group')).display,
                users: getComputedStyle(document.querySelector('.who_users')).display,
            })
            JS);
        if (! is_string($result['group'] ?? null) || ! is_string($result['users'] ?? null)) {
            throw new RuntimeException('unexpected displays shape: ' . var_export($result, true));
        }

        return [
            'group' => $result['group'],
            'users' => $result['users'],
        ];
    };

    $initial = $displays();
    expect($initial['group'])->not->toBe('none');
    expect($initial['users'])->toBe('none');

    $page->click('label.font-checkbox:has(input[name=who][value=users])');

    $afterUsers = $displays();
    expect($afterUsers['users'])->not->toBe('none');
    expect($afterUsers['group'])->toBe('none');

    $page->click('label.font-checkbox:has(input[name=who][value=group])');

    $afterGroup = $displays();
    expect($afterGroup['group'])->not->toBe('none');
    expect($afterGroup['users'])->toBe('none');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'album notification who-panel toggle');
});

it('blocks the send when no recipient is actually selected, allows it once one is', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Album Notification Guard Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];

    $page = H::navigateOk($page, '/admin.php?page=album-' . $albumId . '-notification');

    // The users panel, not the group one: `<select name="group">` has no
    // `multiple` attribute and no blank placeholder option, so a plain
    // <select>'s own HTML default already leaves its first real <option>
    // selected before any script runs -- true regardless of this
    // conversion. `<select name="users[]" multiple>` has no such default,
    // so it is the one that actually starts with nothing selected.
    $page->click('label.font-checkbox:has(input[name=who][value=users])');

    // Always prevents the real submission ourselves, then reports whether
    // the app's own handler already had -- this decouples "did the app
    // want to block it" from "does this test actually navigate away".
    // Registered after album_notification.ts's own submit listener
    // (attached during ready(), well before this call), so it observes
    // that handler's final defaultPrevented state.
    $page->script(<<<'JS'
        document.getElementById('categoryNotify').addEventListener('submit', (e) => {
            window.__wasPrevented = e.defaultPrevented;
            e.preventDefault();
        })
        JS);

    $page->click('button[name=submitEmail]');
    $blockedWithNoSelection = $page->script('window.__wasPrevented');
    expect($blockedWithNoSelection)
        ->toBeTrue();

    // Through selectize's own API, not by setting `.selected` directly --
    // selectize.js detaches every original <option> from the underlying
    // <select> on init ($input.children().detach(), keeping them only to
    // restore on destroy()), so there is no unselected <option> element
    // left to select there any more. `.selectize.addItem()` is what
    // re-adds a real, selected <option> via the widget's own
    // updateOriginalInput() -- the same real DOM state
    // `whoSelect.selectedOptions` (album_notification.ts's own submit
    // guard) reads. User id 1 is fixture_admin, always a valid recipient
    // for a public album (AlbumNotificationPageRendererTest.php's own
    // sibling test already sends to this same id).
    $page->script(<<<'JS'
        document.querySelector('.who_users select').selectize.addItem('1')
        JS);

    $page->click('button[name=submitEmail]');
    $blockedWithSelection = $page->script('window.__wasPrevented');
    expect($blockedWithSelection)
        ->toBeFalse();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'album notification submit guard');
});
