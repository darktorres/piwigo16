<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/default/js/thumbnails.loader.ts. A snapshot
 * (golden-html/VR) cannot see this behaviour at all: thumbnails.latte
 * renders the placeholder `img_small.png` + `data-src` markup identically
 * whether or not the JS afterward ever swaps it for the real derivative --
 * only a live page can tell the two apart. This is also the one real
 * first-party call site of `jQuery.manageAjax` (build/jquery-plugins.d.ts),
 * so it stays jQuery for the queue itself until that library ports in
 * P49-B group 2 -- only the `.data()`/`.attr()`/`.show()`/`.hide()`/
 * `.each()`/`ready()` calls around it convert here.
 *
 * DerivativeImage::isCached() only checks the derivative file's real mtime
 * under `derivative_url_style` 0 ("auto" -- CurrentConfig.php's own
 * docblock: "static link if already cached, else routed through i.php").
 * Style 1 ("always a static link") and the default 2 ("always routed
 * through i.php") both skip that check entirely and leave `is_cached` at
 * its hardcoded-true default regardless of whether the file exists --
 * confirmed live, not assumed: both produced a direct `_data/i/...`
 * `src` with no `data-src` at all for a brand-new, never-generated photo.
 * Only style 0 makes a fresh photo (unique per test, via uniqid()) render
 * the placeholder thumbnails.loader.ts actually swaps out.
 */
it('replaces the placeholder thumbnail with the real derivative via the ajax queue', function (): void {
    $snapshot = H::snapshotConfig(['derivative_url_style']);
    H::setConfigValue('derivative_url_style', '0');

    try {
        $page = H::asAdmin($this);
        $album = H::createCategory($page, [
            'name' => 'Thumbnails Loader Album ' . uniqid(),
        ]);
        if (! is_numeric($album['id'] ?? null)) {
            throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $album['id'];
        $image = H::makeTestImage(uniqid());
        H::uploadPhotoViaApi($image, $albumId, 'Thumbnails Loader Photo');
        @unlink($image);

        $page = H::navigateOk($page, '/index.php?/category/' . $albumId);

        $result = H::scriptArray($page, <<<'JS'
            new Promise((resolve, reject) => {
                const deadline = Date.now() + 5000;
                const check = () => {
                    const img = document.querySelector('img[data-src]');
                    if (img === null) {
                        return reject(new Error('no img[data-src] found on the category page'));
                    }
                    if (!img.src.includes('img_small.png')) {
                        const loader = document.querySelector('.loader');
                        return resolve({
                            src: img.src,
                            dataSrc: img.getAttribute('data-src'),
                            loaderDisplay: loader === null ? null : getComputedStyle(loader).display,
                        });
                    }
                    if (Date.now() > deadline) {
                        return reject(new Error('thumbnail never swapped from the placeholder, still ' + img.src));
                    }
                    setTimeout(check, 100);
                };
                check();
            })
            JS);

        if (
            ! is_string($result['src'] ?? null)
            || ! is_string($result['dataSrc'] ?? null)
            || (! is_string($result['loaderDisplay'] ?? null) && ($result['loaderDisplay'] ?? null) !== null)
        ) {
            throw new RuntimeException('unexpected result shape: ' . var_export($result, true));
        }

        // `data-src` is the ajaxload URL (i.php?/...) thumbnails.loader.ts
        // requests -- untouched by the swap, jQuery never removed it either.
        // The response's `result.url` is a *different*, now-cached direct
        // link (DerivativeImage::isCached() is true by the time the
        // ajaxload request itself runs the generation), which is what ends
        // up in `src`.
        expect($result['dataSrc'])->toContain('i.php');
        expect($result['src'])->toContain('-th.jpg');
        expect($result['src'])->not->toContain('img_small.png');
        expect($result['loaderDisplay'])->toBe('none');

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'thumbnails loader swap');
    } finally {
        H::restoreConfig($snapshot);
    }
});
