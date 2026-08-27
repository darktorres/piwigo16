<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

// Both pages here are entirely show/hide and class toggling driven by
// clicks. The server renders one state; every other state exists only after
// a user acts, so golden-html and visual regression see one arrangement and
// nothing else. These two tests walk the transitions.

it('reveals and conceals a search filter row with its checkbox', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=configuration&section=search');

    // The Words filter is enabled by default, so its select and arrow are
    // showing and the "default" checkbox's container with them. The checkbox
    // itself carries the `hidden` attribute and is never visible -- the
    // container around it is what the code shows and hides.
    $page->assertVisible('#fwordsSelect');
    $page->assertVisible('#wordsArrow');
    $page->assertVisible('label.filter-manager-options-container:has(#default_words)');

    // The real control is the label: the admin theme replaces the checkbox
    // itself with a font icon.
    $page->click('label.font-checkbox:has(#wordsFilters)');

    $page->assertMissing('#fwordsSelect');
    $page->assertMissing('#wordsArrow');
    // Hidden via its *parent*, not itself -- `.parent().hide()` in the
    // original.
    $page->assertMissing('label.filter-manager-options-container:has(#default_words)');

    $page->click('label.font-checkbox:has(#wordsFilters)');

    $page->assertVisible('#fwordsSelect');
    $page->assertVisible('#wordsArrow');
    $page->assertVisible('label.filter-manager-options-container:has(#default_words)');

    $page->assertNoJavaScriptErrors();
});

it('marks the chosen filter as the default one', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=configuration&section=search');

    /** @var bool $startsChecked */
    $startsChecked = $page->script(
        "document.getElementById('default_words').checked"
    );

    /** @var bool $startsMarked */
    $startsMarked = $page->script(
        "document.getElementById('default_words').parentElement.classList.contains('selected-filter-container')"
    );

    // The class tracks the checkbox, so whatever the stored state is, the
    // container must already agree with it before anything is clicked.
    expect($startsMarked)
        ->toBe($startsChecked);

    // The checkbox is `hidden`; its own container label is the control.
    $page->click('label.filter-manager-options-container:has(#default_words)');

    /** @var bool $afterMarked */
    $afterMarked = $page->script(
        "document.getElementById('default_words').parentElement.classList.contains('selected-filter-container')"
    );

    expect($afterMarked)
        ->toBe(! $startsChecked);

    $page->assertNoJavaScriptErrors();
});

it('switches the selected standard-pages skin and its previews', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=themes&tab=standard_pages');

    /** @var array{selected: string, hidden: string, light: string} $before */
    $before = $page->script(<<<'JS'
    (() => ({
        selected: (document.querySelector('.std_pgs_mini_previews img.selected') || {}).id || '',
        hidden: document.querySelector('input[name=std_pgs_selected_skin]').value,
        light: document.querySelector('.std_pgs_selected_preview img#preview-light').getAttribute('src'),
    }))()
    JS);

    expect($before['selected'])->toBe('default');

    $page->click('.std_pgs_mini_previews img#cobalt');

    /** @var array{selected: string, count: int, hidden: string, light: string, dark: string} $after */
    $after = $page->script(<<<'JS'
    (() => ({
        selected: (document.querySelector('.std_pgs_mini_previews img.selected') || {}).id || '',
        count: document.querySelectorAll('.std_pgs_mini_previews img.selected').length,
        hidden: document.querySelector('input[name=std_pgs_selected_skin]').value,
        light: document.querySelector('.std_pgs_selected_preview img#preview-light').getAttribute('src'),
        dark: document.querySelector('.std_pgs_selected_preview img#preview-dark').getAttribute('src'),
    }))()
    JS);

    // Exactly one outline: the handler clears the class from every mini
    // before setting it on the one clicked.
    expect($after['selected'])->toBe('cobalt');
    expect($after['count'])->toBe(1);

    // The hidden field is what the form actually submits.
    expect($after['hidden'])->toBe('cobalt');

    // Both large previews follow, built from the clicked image's own id.
    expect($after['light'])->toBe('themes/standard_pages/skins/light-cobalt.jpg');
    expect($after['dark'])->toBe('themes/standard_pages/skins/dark-cobalt.jpg');
    expect($after['light'])->not->toBe($before['light']);

    $page->assertNoJavaScriptErrors();
});

it('swaps the custom logo upload and reuse panels', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=themes&tab=standard_pages');

    // The panels live inside `.custom_logo_preview`, which starts carrying
    // the `hide` class because the stored choice is the Piwigo logo.
    $page->click('label.font-checkbox:has(input[value="custom_logo"])');

    /** @var array{shown: int, hidden: int} $classes */
    $classes = $page->script(<<<'JS'
    (() => ({
        shown: document.querySelectorAll('.custom_logo_preview.show').length,
        hidden: document.querySelectorAll('.custom_logo_preview.hide').length,
    }))()
    JS);

    expect($classes['shown'])->toBeGreaterThan(0);
    expect($classes['hidden'])->toBe(0);

    // Only the reuse panel is rendered here. `#change_logo` and
    // `.change_logo_container` appear alongside it once a custom logo is
    // actually stored, so the other direction of this swap is not reachable
    // from the fixture's state and is deliberately not asserted.
    $page->assertVisible('.use_existing_logo_container');
    $page->assertNotPresent('#change_logo');

    $page->click('#use_existing_logo');
    $page->assertMissing('.use_existing_logo_container');

    // Cancelling also clears the pending file selection.
    /** @var string $logoValue */
    $logoValue = $page->script("document.getElementById('std_pgs_logo').value");
    expect($logoValue)
        ->toBe('');

    $page->assertNoJavaScriptErrors();
});
