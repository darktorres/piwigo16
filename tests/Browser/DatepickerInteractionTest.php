<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-B native port of jQuery UI's datepicker widget + jquery-timepicker-
 * addon (`themes/default/js/vendor/datepicker.ts`, `pwgDatepicker`) --
 * the last unstarted P49-B surface. No prior test, jQuery-based or not,
 * ever drove the calendar/time-slider/cancel/unset UI itself; every
 * existing datepicker-adjacent test (BatchManagerUnitInteractionTest.php,
 * HistoryInteractionTest.php) only ever asserted the *consequences* of a
 * field change, never the picker's own interactive surface.
 *
 * `picture_modify.php`'s own `date_creation` field is real, linked
 * (`data-datepicker-unset`), `showTimepicker: true`, `cancelButton` --
 * the widest single real-call-site configuration -- so the open/select/
 * time-slider/Done/Cancel/unset behaviors below all target it.
 * `history.php`'s own `start`/`end` pair is the one real
 * `data-datepicker-start`/`data-datepicker-end` cross-linked
 * configuration, covered separately below.
 */
it('opens the calendar, selects a day, adjusts the time via the hour/minute sliders, and commits on Done', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Datepicker Interaction Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $imagePath = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($imagePath, (int) $album['id'], 'Datepicker Interaction Photo');
    @unlink($imagePath);

    $page = H::navigateOk($page, '/admin.php?page=photo-' . $imageId);

    $page->click('input[data-datepicker="date_creation"]');

    // Only real, non-other-month, non-disabled days render as `<a
    // class="ui-state-default">` (vendor/datepicker.ts's own
    // renderCalendar()) -- day 15 exists, and is never "other month", in
    // every real month, so this is robust regardless of the current date.
    $page->script(<<<'JS'
        Array.from(document.querySelectorAll('.ui-datepicker-calendar a.ui-state-default'))
            .find((a) => a.textContent === '15')
            .click();
        JS);

    $visibleAfterDaySelect = H::scriptString(
        $page,
        'document.querySelector(\'input[data-datepicker="date_creation"]\').value',
    );
    $hiddenAfterDaySelect = H::scriptString(
        $page,
        'document.querySelector(\'input[name="date_creation"]\').value',
    );

    expect($visibleAfterDaySelect)
        ->toMatch('/^\S+ 15 \S+ \d{4} \d{2}:\d{2}$/');
    expect($hiddenAfterDaySelect)
        ->toMatch('/^\d{4}-\d{2}-15 \d{2}:\d{2}:00$/');

    // showTimepicker keeps the popup open across a day selection (see
    // datepicker.ts's own leading comment) -- the time sliders below are
    // exercised on the same, still-open popup, no re-open needed.
    $popupOpen = H::scriptBool($page, "document.querySelector('.ui-datepicker').style.display === 'block'");
    expect($popupOpen)
        ->toBeTrue();

    if (
        preg_match('/^(\d{4}-\d{2}-15) (\d{2}):(\d{2}):00$/', $hiddenAfterDaySelect, $m) !== 1
    ) {
        throw new RuntimeException('unexpected hidden field format: ' . $hiddenAfterDaySelect);
    }
    $datePart = $m[1];
    $beforeHour = (int) $m[2];
    $beforeMinute = (int) $m[3];

    // vendor/slider.ts's own hour/minute sliders (min:0/max:23,
    // min:0/max:59) clamp rather than wrap at their own bound
    // (`if (curVal === state.max) return;`) -- accounted for here rather
    // than assuming modulo wraparound, so this stays correct at the
    // 23:xx/xx:59 edge.
    $expectedHour = min($beforeHour + 1, 23);
    $expectedMinute = min($beforeMinute + 1, 59);

    // vendor/slider.ts's own keyboard handling (ArrowUp/step:1) fires a
    // real slide+stop+change synchronously -- no waiting needed.
    $page->script(<<<'JS'
        document.querySelector('.ui_tpicker_hour_slider .ui-slider-handle')
            .dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true, cancelable: true }));
        JS);
    $page->script(<<<'JS'
        document.querySelector('.ui_tpicker_minute_slider .ui-slider-handle')
            .dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true, cancelable: true }));
        JS);

    $hiddenAfterSliders = H::scriptString(
        $page,
        'document.querySelector(\'input[name="date_creation"]\').value',
    );
    expect($hiddenAfterSliders)
        ->toBe(sprintf('%s %02d:%02d:00', $datePart, $expectedHour, $expectedMinute));

    $page->click('.ui-datepicker-close');

    $popupClosedAfterDone = H::scriptBool($page, "document.querySelector('.ui-datepicker').style.display === 'none'");
    expect($popupClosedAfterDone)
        ->toBeTrue();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'datepicker open/select/time-slider/Done');
});

it('reverts to the original value when Cancel is clicked', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Datepicker Cancel Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $imagePath = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($imagePath, (int) $album['id'], 'Datepicker Cancel Photo');
    @unlink($imagePath);

    $page = H::navigateOk($page, '/admin.php?page=photo-' . $imageId);

    $originalHidden = H::scriptString(
        $page,
        'document.querySelector(\'input[name="date_creation"]\').value',
    );

    $page->click('input[data-datepicker="date_creation"]');
    $page->script(<<<'JS'
        Array.from(document.querySelectorAll('.ui-datepicker-calendar a.ui-state-default'))
            .find((a) => a.textContent === '15')
            .click();
        JS);

    $hiddenAfterSelect = H::scriptString(
        $page,
        'document.querySelector(\'input[name="date_creation"]\').value',
    );
    expect($hiddenAfterSelect)
        ->not->toBe($originalHidden);

    $page->click('.pwg-datepicker-cancel');

    $hiddenAfterCancel = H::scriptString(
        $page,
        'document.querySelector(\'input[name="date_creation"]\').value',
    );
    $popupClosedAfterCancel = H::scriptBool($page, "document.querySelector('.ui-datepicker').style.display === 'none'");

    expect($hiddenAfterCancel)
        ->toBe($originalHidden);
    expect($popupClosedAfterCancel)
        ->toBeTrue();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'datepicker Cancel reverts');
});

it('clears the value when the unset link is clicked', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Datepicker Unset Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $imagePath = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($imagePath, (int) $album['id'], 'Datepicker Unset Photo');
    @unlink($imagePath);

    $page = H::navigateOk($page, '/admin.php?page=photo-' . $imageId);

    // A freshly-uploaded test image carries no EXIF creation date, so
    // `date_creation` starts genuinely empty -- select a real day first
    // to give the unset link something to actually clear.
    $page->click('input[data-datepicker="date_creation"]');
    $page->script(<<<'JS'
        Array.from(document.querySelectorAll('.ui-datepicker-calendar a.ui-state-default'))
            .find((a) => a.textContent === '15')
            .click();
        JS);
    $page->click('.ui-datepicker-close');

    $originalHidden = H::scriptString(
        $page,
        'document.querySelector(\'input[name="date_creation"]\').value',
    );
    expect($originalHidden)
        ->not->toBe('');

    $page->click('#date_creation_unset');

    $hiddenAfterUnset = H::scriptString(
        $page,
        'document.querySelector(\'input[name="date_creation"]\').value',
    );
    $visibleAfterUnset = H::scriptString(
        $page,
        'document.querySelector(\'input[data-datepicker="date_creation"]\').value',
    );

    expect($hiddenAfterUnset)
        ->toBe('');
    expect($visibleAfterUnset)
        ->toBe('');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'datepicker unset link');
});

/**
 * `history.php`'s own real `data-datepicker-start`/`data-datepicker-end`
 * cross-linking (`vendor/datepicker.ts`'s own `linkRange()`): closing
 * the start picker with a day selected constrains the end picker's own
 * calendar, disabling every day before it -- the one real behavior
 * `picture_modify.php`'s own single, unlinked picker above can't cover.
 */
it("constrains the end picker's calendar to the start picker's own selected day", function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=history');

    $page->click('input[data-datepicker="start"]');
    $page->script(<<<'JS'
        Array.from(document.querySelectorAll('.ui-datepicker-calendar a.ui-state-default'))
            .find((a) => a.textContent === '15')
            .click();
        JS);

    // Not `showTimepicker` here (history.php's own plain, time-less
    // pair), so selecting a day closes the popup immediately
    // (datepicker.ts's own `selectDate()`), which fires the
    // `onCloseNotify` that propagates `minDate` to the end instance.
    $popupClosedAfterStart = H::scriptBool($page, "document.querySelector('.ui-datepicker').style.display === 'none'");
    expect($popupClosedAfterStart)
        ->toBeTrue();

    $page->click('input[data-datepicker="end"]');

    // Both pickers open with no prior `selected` to the same, current
    // month (vendor/datepicker.ts's own `showPopup()`: `base = inst.
    // selected ?? stripTime(new Date())`), so day 14 (the day right
    // before the real day 15 just selected on start) is guaranteed to
    // be in the end picker's own currently-drawn month too.
    $day14Disabled = H::scriptBool($page, <<<'JS'
        Array.from(document.querySelectorAll('.ui-datepicker-calendar td'))
            .some((td) => td.textContent.trim() === '14' && td.classList.contains('ui-datepicker-unselectable'))
        JS);
    $day16Enabled = H::scriptBool($page, <<<'JS'
        Array.from(document.querySelectorAll('.ui-datepicker-calendar a.ui-state-default'))
            .some((a) => a.textContent === '16')
        JS);

    expect($day14Disabled)
        ->toBeTrue();
    expect($day16Enabled)
        ->toBeTrue();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'datepicker start/end cross-linking');
});
