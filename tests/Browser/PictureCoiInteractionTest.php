<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Real, mutation-verified interactive coverage of `vendor/widgets/jcrop.ts`
 * (P49-B group 6, the last "group 6"-labeled library) -- drawing a new
 * selection, moving it, and resizing it via a handle. None of this was
 * ever behaviorally tested before, jQuery-based or not:
 * `PictureCoiPageRendererTest.php`'s own coverage only ever drives the
 * form submission directly, or the `animateTo()`-restore round trip on
 * page load -- never the drag interaction that produces `#l`/`#t`/`#r`/
 * `#b` in the first place.
 *
 * A raw dispatched `pointerdown`/`pointermove`/`pointerup` sequence is
 * used throughout (not Pest Browser's own locator-to-locator `drag()`),
 * for the same reason `AlbumTreeTest.php`'s own drag-and-drop tests do:
 * pixel-precise control over where each drag starts/ends, needed here
 * to land on a specific resize handle and to assert an exact resulting
 * fraction rather than "somewhere in the right direction".
 */
function jcropPointerDragScript(string $js): string
{
    return <<<JS
        new Promise((resolve, reject) => {
            function dispatch(type, x, y) {
                const ev = new PointerEvent(type, {
                    bubbles: true,
                    cancelable: true,
                    pointerId: 1,
                    pointerType: 'mouse',
                    clientX: x,
                    clientY: y,
                    button: 0,
                    buttons: type === 'pointerup' ? 0 : 1,
                });
                (document.elementFromPoint(x, y) || document).dispatchEvent(ev);
            }
            const wait = (ms) => new Promise((r) => setTimeout(r, ms));

            (async () => {
                const holder = document.querySelector('.jcrop-holder');
                if (!holder) {
                    reject(new Error('jcrop holder never rendered'));
                    return;
                }
                {$js}
            })();
        })
        JS;
}

/**
 * Narrows the `l`/`t`/`r`/`b` fields of a `scriptJson()` result (typed
 * `mixed` until checked) to floats -- PHPStan rejects a bare `(float)`
 * cast of `mixed` (`cast.double`), so this validates each field is a
 * numeric string first, matching the `is_numeric`-before-cast
 * convention `BatchManagerFilterInteractionTest.php` already uses for
 * the same shape of scriptJson() result.
 *
 * @param array<array-key, mixed> $result
 * @return array{l: float, t: float, r: float, b: float}
 */
function jcropCoordsFloat(array $result): array
{
    foreach (['l', 't', 'r', 'b'] as $field) {
        if (! is_numeric($result[$field] ?? null)) {
            throw new RuntimeException("unexpected coordinate shape for '{$field}': " . var_export($result, true));
        }
    }

    return [
        'l' => (float) $result['l'],
        't' => (float) $result['t'],
        'r' => (float) $result['r'],
        'b' => (float) $result['b'],
    ];
}

it('draws a new center-of-interest selection by dragging on the crop image', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Jcrop Draw Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Jcrop Draw Photo');
    @unlink($image);

    $page = H::navigateOk($page, '/admin.php?page=picture_coi&image_id=' . $imageId);

    $result = H::scriptJson($page, jcropPointerDragScript(<<<'JS'
        const rect = holder.getBoundingClientRect();
        // makeTestImage() defaults to 200x150, well under the 500x400
        // box picture_coi.ts asks Jcrop for, so display pixels equal
        // real image pixels (no additional presize() scale-down).
        const sx = rect.left + 20, sy = rect.top + 15, ex = rect.left + 120, ey = rect.top + 75;
        dispatch('pointerdown', sx, sy);
        for (let i = 1; i <= 5; i++) {
            dispatch('pointermove', sx + (ex - sx) * i / 5, sy + (ey - sy) * i / 5);
        }
        dispatch('pointerup', ex, ey);
        await wait(50);
        resolve(JSON.stringify({
            l: document.getElementById('l').value,
            t: document.getElementById('t').value,
            r: document.getElementById('r').value,
            b: document.getElementById('b').value,
        }));
        JS));

    $coords = jcropCoordsFloat($result);
    expect($coords['l'])->toBe(0.1);
    expect($coords['t'])->toBe(0.1);
    expect($coords['r'])->toBe(0.6);
    expect($coords['b'])->toBe(0.5);

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'jcrop draw new selection');
});

it('moves an existing selection by dragging inside it, then resizes it via a corner handle', function (): void {
    // The single strongest regression net for the exact anchor-corner
    // bug this session's own port work found and fixed live: dragging a
    // corner handle re-anchored the *wrong* corner (the one just
    // dragged, not its opposite), so a resize silently relocated the
    // whole selection instead of resizing it in place. Move-then-resize
    // in one test catches that directly -- if the anchor were wrong,
    // the assertions on l/t below (expected to survive the resize
    // untouched) would fail.
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Jcrop Move Resize Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Jcrop Move Resize Photo');
    @unlink($image);

    $page = H::navigateOk($page, '/admin.php?page=picture_coi&image_id=' . $imageId);

    $result = H::scriptJson($page, jcropPointerDragScript(<<<'JS'
        const rect = holder.getBoundingClientRect();

        // 1. Draw (20,20)-(120,80).
        let sx = rect.left + 20, sy = rect.top + 20, ex = rect.left + 120, ey = rect.top + 80;
        dispatch('pointerdown', sx, sy);
        for (let i = 1; i <= 5; i++) {
            dispatch('pointermove', sx + (ex - sx) * i / 5, sy + (ey - sy) * i / 5);
        }
        dispatch('pointerup', ex, ey);
        await wait(50);

        // 2. Move it by (+30, +10): drag from its own center.
        const cx = rect.left + 85, cy = rect.top + 65;
        dispatch('pointerdown', cx, cy);
        for (let i = 1; i <= 5; i++) {
            dispatch('pointermove', cx + 30 * i / 5, cy + 10 * i / 5);
        }
        dispatch('pointerup', cx + 30, cy + 10);
        await wait(50);

        // 3. Resize via the SE handle, dragging it further out by
        // (+20, +20) -- the NW corner (now at 50,30 after the move)
        // must stay exactly where the move left it.
        const seHandle = document.querySelector('.jcrop-handle.ord-se');
        const hrect = seHandle.getBoundingClientRect();
        const hx = hrect.left + hrect.width / 2, hy = hrect.top + hrect.height / 2;
        dispatch('pointerdown', hx, hy);
        for (let i = 1; i <= 5; i++) {
            dispatch('pointermove', hx + 20 * i / 5, hy + 20 * i / 5);
        }
        dispatch('pointerup', hx + 20, hy + 20);
        await wait(50);

        resolve(JSON.stringify({
            l: document.getElementById('l').value,
            t: document.getElementById('t').value,
            r: document.getElementById('r').value,
            b: document.getElementById('b').value,
        }));
        JS));

    $coords = jcropCoordsFloat($result);
    // NW corner (50, 30 in display px, on a 200x150 image) must have
    // survived the resize untouched -- the real regression this test
    // exists to catch.
    expect($coords['l'])->toBe(0.25);
    expect($coords['t'])->toBe(0.2);
    // SE corner: (120,80) drawn, +(30,10) moved to (150,90), +(20,20)
    // resized to (170,110) -- 170/200 and 110/150.
    expect($coords['r'])->toBe(0.85);
    expect($coords['b'])->toBe(0.7333333333333333);

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'jcrop move then resize');
});

it('releases the selection on Escape, clearing the coordinate inputs', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Jcrop Escape Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Jcrop Escape Photo');
    @unlink($image);

    $page = H::navigateOk($page, '/admin.php?page=picture_coi&image_id=' . $imageId);

    $result = H::scriptJson($page, jcropPointerDragScript(<<<'JS'
        const rect = holder.getBoundingClientRect();
        const sx = rect.left + 20, sy = rect.top + 15, ex = rect.left + 120, ey = rect.top + 75;
        dispatch('pointerdown', sx, sy);
        for (let i = 1; i <= 5; i++) {
            dispatch('pointermove', sx + (ex - sx) * i / 5, sy + (ey - sy) * i / 5);
        }
        dispatch('pointerup', ex, ey);
        await wait(50);
        const beforeEscape = document.getElementById('l').value;

        // Arrow-key nudging and Escape-to-release (KeyManager's real
        // mechanism) both need a real focused element to receive the
        // keydown -- a drag leaves jcrop's own hidden key-nudge proxy
        // button focused, matching real usage.
        document.activeElement.dispatchEvent(new KeyboardEvent('keydown', {
            key: 'Escape',
            bubbles: true,
            cancelable: true,
        }));
        await wait(50);

        resolve(JSON.stringify({
            beforeEscape,
            l: document.getElementById('l').value,
            t: document.getElementById('t').value,
            r: document.getElementById('r').value,
            b: document.getElementById('b').value,
        }));
        JS));

    expect($result['beforeEscape'])->not->toBe('');
    expect($result['l'])->toBe('');
    expect($result['t'])->toBe('');
    expect($result['r'])->toBe('');
    expect($result['b'])->toBe('');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'jcrop Escape releases selection');
});
