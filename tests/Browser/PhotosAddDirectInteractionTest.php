<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/photosAddDirect.ts -- 0%
 * prior live-interaction coverage (PhotosAddDirectPageRendererTest.php
 * only ever asserts the rendered page, or drives a preference/config
 * change directly through the server rather than the client-side handler
 * that would normally trigger it).
 *
 * Three handlers this file wires up have no reachable template call
 * site at all (confirmed by grep across every admin template, matching
 * the same class of finding already recorded for categories/modify.ts's
 * `.unlock-album`): `#showPermissions`/`.showFieldset`,
 * `#uploadWarningsSummary a.showInfo` + its `#uploadWarnings` target,
 * and (unreachably in this fixture, since it always has albums)
 * the "add your first album" onboarding flow (`#btnFirstAlbum` and
 * friends, gated on `nb_albums === 0`). None are fixed here -- P49 is
 * translation-only, and a handler binding zero elements is harmless.
 *
 * `.pluploadQueue()` is a real native port now (P49-C,
 * `vendor/utils/uploadQueue.ts`) -- see the tests further down for its own
 * real file-selection/validation/queue/drag-drop coverage. `$.alert()`
 * (jquery-confirm, group 5) is untouched and unexercised here (it needs a
 * real ambiguous-filename detection during a real upload).
 */
it('follows the format-mode switch\'s data-url and marks the slider loading', function (): void {
    $snapshot = H::snapshotConfig(['enable_formats']);
    H::setConfigValue('enable_formats', 'true');

    try {
        $page = H::asAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=photos_add&album=1');
        $page->assertPresent('.format-mode-group-manager .switch');

        $url = $page->script(
            "document.querySelector('.format-mode-group-manager .switch').dataset.switchFormatModeUrl"
        );
        expect($url)
            ->toContain('formats');

        // Overwrite the data-url to a same-document hash first -- proves
        // the handler's own logic (read data-switch-format-mode-url, add
        // the loading class, hand off to window.location.replace()) by
        // driving it through a real `location.replace()` call that
        // doesn't leave the page, rather than either following the real
        // destination (which needs its own album/most-recent-image
        // context this test doesn't control) or trying to stub
        // `location.replace` itself (non-writable in a real browser).
        $page->script(
            "document.querySelector('.format-mode-group-manager .switch').dataset.switchFormatModeUrl = '#stub-format-mode'"
        );

        $page->click('.format-mode-group-manager .switch');

        expect($page->script('window.location.hash'))
            ->toBe('#stub-format-mode');
        expect($page->script("document.querySelector('.switch .slider').classList.contains('loading')"))
            ->toBeTrue();

        H::assertNoServerErrors($page, 'photos_add_direct format-mode switch');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('toggles the upload-options panel open and closed', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=photos_add&album=1');
    $page->assertPresent('#uploadOptionsContent');

    $waitForDisplay = static function (Webpage|PendingAwaitablePage|AwaitableWebpage $page, string $expected, string $failMessage): void {
        $page->script(
            <<<JS
            new Promise((resolve, reject) => {
                const deadline = Date.now() + 5000;
                const check = () => {
                    if (getComputedStyle(document.getElementById('uploadOptionsContent')).display === '{$expected}') {
                        return resolve(true);
                    }
                    if (Date.now() > deadline) {
                        return reject(new Error('{$failMessage}'));
                    }
                    setTimeout(check, 100);
                };
                check();
            })
            JS
            ,
        );
    };
    $sleep = static function (Webpage|PendingAwaitablePage|AwaitableWebpage $page, int $ms): void {
        $page->script("new Promise((resolve) => setTimeout(resolve, {$ms}))");
    };

    expect($page->script("getComputedStyle(document.getElementById('uploadOptionsContent')).display"))
        ->toBe('none');

    $page->click('#uploadOptions');
    $waitForDisplay($page, 'block', 'upload options panel never opened');
    expect($page->script("document.getElementById('uploadOptions').classList.contains('options-open')"))
        ->toBeTrue();

    // slideToggle() has no completion callback here, but the same
    // animation-duration race documented in users/activity.ts's own test
    // applies: give it time to fully settle before toggling again.
    $sleep($page, 500);

    $page->click('#uploadOptions');
    $waitForDisplay($page, 'none', 'upload options panel never closed');
    expect($page->script("document.getElementById('uploadOptions').classList.contains('options-open')"))
        ->toBeFalse();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'photos_add_direct upload-options toggle');
});

/**
 * Piecon (P49-C native port, `vendor/widgets/piecon.ts`) -- no prior test, npm
 * package or not, ever drove its own favicon-drawing behavior. plupload
 * is a real native port now too (P49-C, `vendor/utils/uploadQueue.ts`), which
 * exposes no global the way `jQuery('#uploader').pluploadQueue()` used
 * to (matching this whole campaign's own module-scoping convention) --
 * so this drives a real file through the real upload queue's own hidden
 * `<input type="file">` (`vendor/utils/uploadQueue.ts`'s own real
 * browse-button proxy target) and a real click on `#startUpload`,
 * reaching Piecon through the real `UploadProgress`/`UploadComplete`
 * pipeline via an actual tus upload rather than any internals stub.
 */
it('draws a progress favicon during upload and resets it on complete', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=photos_add&album=1');

    $originalHref = H::scriptString(
        $page,
        "document.querySelector('link[rel=\"icon\"]').getAttribute('href')",
    );

    $image = H::makeTestImage(uniqid());
    $page->attach('#uploader input[type="file"]', $image);
    @unlink($image);

    $page->click('#startUpload');

    $sawProgressFavicon = $page->script(
        'new Promise((resolve, reject) => {'
        . 'const deadline = Date.now() + 10000;'
        . 'let sawProgress = false;'
        . 'const originalHref = ' . json_encode($originalHref) . ';'
        . 'const check = () => {'
        . "const href = document.querySelector('link[rel=\"icon\"]').getAttribute('href');"
        . "if (href && href.startsWith('data:image/png;base64,')) sawProgress = true;"
        . 'if (sawProgress && href === originalHref) return resolve(true);'
        . "if (Date.now() > deadline) return reject(new Error('upload never completed with an observed progress favicon (sawProgress=' + sawProgress + ', href=' + href + ')'));"
        . 'setTimeout(check, 30);'
        . '};'
        . 'check();'
        . '})',
    );
    expect($sawProgressFavicon)
        ->toBeTrue();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'photos_add_direct piecon progress favicon');
});

/**
 * `vendor/utils/uploadQueue.ts`'s own real per-file extension-filter rejection
 * -- real algorithm read from `plupload.dev.js`'s own `mime_types` file
 * filter, real alert() text read from `jquery.plupload.queue.js`'s own
 * `Error` binding. A disallowed extension is never even added to the
 * visible queue (`#uploader_filelist` stays at 0 real rows).
 */
it('rejects a disallowed file extension with an alert and an appended error', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=photos_add&album=1');

    $page->script(
        'window.__uploadQueueAlerts = [];'
        . 'window.alert = (msg) => { window.__uploadQueueAlerts.push(msg); };',
    );

    $page->script(
        "const input = document.querySelector('#uploader input[type=\"file\"]');"
        . 'const dt = new DataTransfer();'
        . "dt.items.add(new File(['not an image'], 'bad-extension-test.txt', {type: 'text/plain'}));"
        . 'input.files = dt.files;'
        . "input.dispatchEvent(new Event('change', {bubbles: true}));",
    );

    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                if (window.__uploadQueueAlerts.length > 0 && document.querySelector('.errors ul')?.textContent) {
                    return resolve(true);
                }
                if (Date.now() > deadline) return reject(new Error('extension error never appeared'));
                setTimeout(check, 50);
            };
            check();
        })
        JS);

    $alerts = H::scriptArray($page, 'window.__uploadQueueAlerts');
    expect($alerts)
        ->toContain('Error: Invalid file extension: bad-extension-test.txt');

    $errorsText = H::scriptString($page, "document.querySelector('.errors ul').textContent");
    expect($errorsText)
        ->toBe('File extension error.');

    $queuedCount = H::scriptInt(
        $page,
        "document.querySelectorAll('#uploader_filelist li:not(.plupload_droptext)').length",
    );
    expect($queuedCount)
        ->toBe(0);

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'photos_add_direct upload queue extension rejection');
});

/**
 * `vendor/utils/uploadQueue.ts`'s own real drag-and-drop -- the real drop
 * zone is the file list `<ul>` itself (`vendor/utils/uploadQueue.ts`'s own
 * leading comment: matches `jquery.plupload.queue.js`'s own real
 * `settings.drop_element = id + '_filelist'`), not the wider
 * `#uploadForm`/`#uploader` container.
 */
it('queues a file dropped onto the file list', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=photos_add&album=1');

    $page->script(
        'const dt = new DataTransfer();'
        . "dt.items.add(new File(['x'], 'dropped.jpg', {type: 'image/jpeg'}));"
        . "document.querySelector('#uploader_filelist').dispatchEvent("
        . "new DragEvent('drop', {bubbles: true, cancelable: true, dataTransfer: dt})"
        . ');',
    );

    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                if (document.querySelectorAll('#uploader_filelist li:not(.plupload_droptext)').length === 1) return resolve(true);
                if (Date.now() > deadline) return reject(new Error('dropped file never queued'));
                setTimeout(check, 50);
            };
            check();
        })
        JS);

    $queuedName = H::scriptString(
        $page,
        "document.querySelector('#uploader_filelist .plupload_file_name span').textContent",
    );
    expect($queuedName)
        ->toBe('dropped.jpg');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'photos_add_direct upload queue drag-drop');
});

/**
 * `vendor/utils/uploadQueue.ts`'s own real per-file remove-from-queue click
 * (only reachable for a still-QUEUED row, matching
 * `jquery.plupload.queue.js`'s own real `$('#'+id+'.plupload_delete a')`
 * selector scoping).
 */
it('removes a queued file when its action icon is clicked', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=photos_add&album=1');

    $page->script(
        "const input = document.querySelector('#uploader input[type=\"file\"]');"
        . 'const dt = new DataTransfer();'
        . "dt.items.add(new File(['x'], 'removable.jpg', {type: 'image/jpeg'}));"
        . 'input.files = dt.files;'
        . "input.dispatchEvent(new Event('change', {bubbles: true}));",
    );

    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                if (document.querySelectorAll('#uploader_filelist li:not(.plupload_droptext)').length === 1) return resolve(true);
                if (Date.now() > deadline) return reject(new Error('file never queued'));
                setTimeout(check, 50);
            };
            check();
        })
        JS);

    $rowClass = H::scriptString(
        $page,
        "document.querySelector('#uploader_filelist li:not(.plupload_droptext)').className",
    );
    expect($rowClass)
        ->toBe('plupload_delete');

    $page->click('#uploader_filelist li:not(.plupload_droptext) .plupload_file_action a');

    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                if (document.querySelectorAll('#uploader_filelist li:not(.plupload_droptext)').length === 0) return resolve(true);
                if (Date.now() > deadline) return reject(new Error('file was never removed'));
                setTimeout(check, 50);
            };
            check();
        })
        JS);

    $droptextVisible = H::scriptBool(
        $page,
        "document.querySelector('#uploader_filelist li.plupload_droptext') !== null",
    );
    expect($droptextVisible)
        ->toBeTrue();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'photos_add_direct upload queue remove');
});

/**
 * `vendor/utils/uploadQueue.ts`'s own real click-to-rename (`rename: formatMode`
 * -- only enabled once formats mode is real, matching
 * `PhotosAddDirectRequest::fromGlobals()`'s own `$isFormatsEnabled &&
 * isset($get['formats'])` gate). Targets a real existing photo via
 * `formats=<id>` (the `haveFormatsOriginal` branch, same real pattern
 * `PhotosAddDirectPageRendererTest.php`'s own "lists a real photo's
 * existing formats..." test uses) rather than the filename-search
 * branch: a name with no matching photo gets the row removed by
 * `photosAddDirect.ts`'s own real `FilesAdded` handler before there's
 * anything left to click.
 */
it('renames a queued file by clicking its name in formats mode', function (): void {
    $snapshot = H::snapshotConfig(['enable_formats']);
    H::setConfigValue('enable_formats', 'true');

    try {
        $page = H::asAdmin($this);
        $album = H::createCategory($page, [
            'name' => 'Photos Add Direct Rename Album ' . uniqid(),
        ]);
        if (! is_numeric($album['id'] ?? null)) {
            throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $album['id'];
        $image = H::makeTestImage(uniqid());
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'Photos Add Direct Rename Photo');
        @unlink($image);

        $page = H::navigateOk($page, '/admin.php?page=photos_add&formats=' . $imageId);
        expect(H::scriptBool($page, "JSON.parse(document.getElementById('page-data').textContent).data.display_formats"))
            ->toBeTrue();

        $page->script(
            "const input = document.querySelector('#uploader input[type=\"file\"]');"
            . 'const dt = new DataTransfer();'
            . "dt.items.add(new File(['x'], 'original-name.dng', {type: 'image/x-adobe-dng'}));"
            . 'input.files = dt.files;'
            . "input.dispatchEvent(new Event('change', {bubbles: true}));",
        );

        $page->script(<<<'JS'
            new Promise((resolve, reject) => {
                const deadline = Date.now() + 5000;
                const check = () => {
                    if (document.querySelectorAll('#uploader_filelist li:not(.plupload_droptext)').length === 1) return resolve(true);
                    if (Date.now() > deadline) return reject(new Error('file never queued'));
                    setTimeout(check, 50);
                };
                check();
            })
            JS);

        $page->click('#uploader_filelist .plupload_file_name span');

        $page->script(<<<'JS'
            new Promise((resolve, reject) => {
                const deadline = Date.now() + 5000;
                const check = () => {
                    if (document.querySelector('#uploader_filelist li input[type="text"]')) return resolve(true);
                    if (Date.now() > deadline) return reject(new Error('rename input never appeared'));
                    setTimeout(check, 50);
                };
                check();
            })
            JS);

        expect(H::scriptString($page, "document.querySelector('#uploader_filelist li input[type=\"text\"]').value"))
            ->toBe('original-name');

        $page->fill('#uploader_filelist li input[type="text"]', 'renamed-file');
        // $page->press() is Dusk-style (clicks a button by its text/name,
        // not a real key send) -- a real "Enter" keydown needs a
        // synthetic KeyboardEvent instead.
        $page->script(
            "document.querySelector('#uploader_filelist li input[type=\"text\"]')"
            . ".dispatchEvent(new KeyboardEvent('keydown', {key: 'Enter', bubbles: true}))",
        );

        $page->script(<<<'JS'
            new Promise((resolve, reject) => {
                const deadline = Date.now() + 5000;
                const check = () => {
                    if (!document.querySelector('#uploader_filelist li input[type="text"]')) return resolve(true);
                    if (Date.now() > deadline) return reject(new Error('rename input never closed'));
                    setTimeout(check, 50);
                };
                check();
            })
            JS);

        expect(H::scriptString($page, "document.querySelector('#uploader_filelist .plupload_file_name span').textContent"))
            ->toBe('renamed-file.dng');

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'photos_add_direct upload queue rename');
    } finally {
        H::restoreConfig($snapshot);
    }
});
