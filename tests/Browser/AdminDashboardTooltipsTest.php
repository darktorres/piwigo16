<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

// The dashboard's storage tooltips are rendered empty by the server and
// filled in entirely by JS: the title, the humanised size, the file count
// and one row per file extension are all written on ready, and the tooltip
// is then positioned against the chart. Golden-html captures the empty
// spans, and visual regression photographs the page with every tooltip
// hidden, so all of it is invisible to both.

it('fills the storage tooltip from the page data', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php');

    // The tooltips are built inside a ready handler, so poll rather than
    // race it.
    $timeoutMs = 10000;
    $page->script(<<<JS
    new Promise((resolve, reject) => {
        const deadline = Date.now() + {$timeoutMs};
        const check = () => {
            const title = document.querySelector('[id^="storage-title-"]');
            if (title !== null && title.innerHTML.trim() !== '') {
                return resolve(true);
            }
            if (Date.now() > deadline) {
                return reject(new Error('Timed out waiting for the storage tooltip to be filled'));
            }
            setTimeout(check, 100);
        };
        check();
    })
    JS);

    /** @var array{title: string, size: string, files: string} $filled */
    $filled = $page->script(<<<'JS'
    (() => {
        const title = document.querySelector('[id^="storage-title-"]');
        const type = title.id.replace('storage-title-', '');

        return {
            title: title.innerHTML.trim(),
            size: document.getElementById('storage-size-' + type).innerHTML.trim(),
            files: document.getElementById('storage-files-' + type).innerHTML.trim(),
        };
    })()
    JS);

    // Each is wrapped in the tag the code adds, so a plain-text write would
    // not satisfy this.
    expect($filled['title'])->toStartWith('<b>');
    expect($filled['size'])->toMatch('/<b>.*(MB|GB).*<\/b>/');
    expect($filled['files'])->toStartWith('<p>');

    $page->assertNoJavaScriptErrors();
});

it('colours each detail row from the chart segment it belongs to', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php');

    /** @var array{rows: int, swatch: string, applied: string} $detail */
    $detail = $page->script(<<<'JS'
    (() => {
        const title = document.querySelector('[id^="storage-title-"]');
        const type = title.id.replace('storage-title-', '');
        const rows = document.querySelectorAll(
            '#storage-detail-' + type + ' .tooltip-details-cont'
        );
        const swatch = document.querySelector(
            '.storage-chart span[data-type="storage-' + type + '"]'
        );
        const coloured = document.querySelector(
            '#storage-' + type + ' .tooltip-details-ext b'
        );

        return {
            rows: rows.length,
            swatch: swatch === null ? '' : getComputedStyle(swatch).backgroundColor,
            applied: coloured === null ? '' : coloured.style.color,
        };
    })()
    JS);

    // The fixture always has a per-extension breakdown, so the
    // no-details branch (which hides the separator with an inline
    // `!important` and zeroes the header margin) is not reachable from
    // here and is deliberately not asserted rather than covered by a
    // conditional that never runs.
    expect($detail['rows'])->toBeGreaterThan(0);

    // The colour is read from the chart segment's *computed* background,
    // not from an inline style, and written onto every extension label.
    expect($detail['swatch'])->not->toBe('');
    expect($detail['applied'])->not->toBe('');

    $page->assertNoJavaScriptErrors();
});

it('shows a storage tooltip while its chart segment is hovered', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php');

    /** @var string $type */
    $type = $page->script(<<<'JS'
    document.querySelector('.storage-chart span[data-type]')
        .getAttribute('data-type').replace('storage-', '')
    JS);

    $page->assertMissing("#storage-{$type}");

    // The segment's mouseenter is bound through the helper's off-then-on
    // pair, so a lost registration shows up here as a tooltip that never
    // appears.
    $page->hover(".storage-chart span[data-type='storage-{$type}']");
    $page->assertVisible("#storage-{$type}");

    $page->assertNoJavaScriptErrors();
});
