<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * The anomaly table's visible text, whitespace-collapsed. Asserting the whole
 * table's text in one string covers the anomaly label and whichever of the
 * five mutually-exclusive message branches ran, in document order, without
 * depending on where check_integrity.latte happens to break its `<label>`
 * tags across lines.
 */
function c13yPanelText(string $html): string
{
    if (preg_match('~<table class="table2".*?</table>~s', $html, $matches) !== 1) {
        return 'NO ANOMALY TABLE';
    }

    return trim((string) preg_replace('/\s+/', ' ', strip_tags($matches[0])));
}

// check_integrity.ts had no coverage in any suite, and could not get any from
// the shared route table: IntroSubController renders CheckIntegrityView only
// when `$c13yResult !== null` -- that is, only when the integrity check
// actually finds an anomaly. The fixture gallery is clean, so `/admin.php`
// renders no panel and never loads the bundle (the admin-dashboard fixture
// contains zero occurrences of it). Covering it therefore means seeding a
// real anomaly.
//
// C13yInternal::c13yUser() is the cheapest true one: it compares the
// configured guest/webmaster users against their expected status, so flipping
// the guest user's status to `normal` produces "Main \"guest\" user status is
// incorrect" through the real check, with no stubbing. `guest_id` is not set
// in config here, so CurrentConfig::$guestId's own default of 2 applies --
// user 2, `guest`.

it('renders the integrity panel and loads its bundle when a real anomaly exists', function (): void {
    $db = H::connect();
    $before = H::dbFetchAssoc($db, 'SELECT status FROM user_infos WHERE user_id = 2');
    $original = is_string($before['status'] ?? null) ? $before['status'] : 'guest';

    try {
        H::dbQuery($db, "UPDATE user_infos SET status = 'normal' WHERE user_id = 2");

        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php');

        // The panel itself, and the bundle that drives it -- the whole point
        // of the test is that neither appears without an anomaly.
        $page->assertPresent('#c13y');
        $page->assertPresent('#checkAllLink');

        $loadsBundle = $page->script(
            "Array.from(document.querySelectorAll('script[src]')).some(s => s.src.includes('checkIntegrity'))"
        );

        expect($loadsBundle)
            ->toBeTrue();

        $page->assertNoJavaScriptErrors();

        // ---- P58 step 0b -------------------------------------------------
        // check_integrity.latte emits 24 Campaign A findings, every one of
        // them off CheckIntegrityView::$c13yList, and nothing asserted any
        // of their *values* -- only that #c13y and #checkAllLink exist. The
        // flatten that produces them (CheckIntegrity.php's own
        // array_map(fn (AnomalyDisplayRow $row) => $row->toArray(), …), one
        // line before the result is built) is step 4's largest single
        // producer, so these are the expressions that retype must leave
        // byte-identical. Asserting them afterwards would prove nothing.
        $html = H::rawWebpage($page)->content();

        // The anomaly's own id reaches the markup four times: the checkbox
        // value, its id, and two label `for`s. Read it back out rather than
        // recomputing the hash, then assert every place it lands.
        if (preg_match('/name="c13y_selection\[\]"\s+value="([^"]+)"/', $html, $m) !== 1) {
            throw new RuntimeException('no c13y selection checkbox rendered: ' . c13yPanelText($html));
        }
        $anomalyId = $m[1];

        expect(substr_count($html, 'c13y_selection-' . $anomalyId))
            ->toBe(4);

        // $c13y['anomaly'] -- the real text the real check produced, as
        // rendered. Note it is the *translated* string: the source key is
        // 'Main "guest" user status is incorrect' and the catalogue turns
        // it into 'The main …', so asserting the key would silently pass
        // against a page that rendered nothing.
        // The whole table's visible text. This anomaly registers a callable
        // correction and is neither corrected nor ignored, so exactly one of
        // the five mutually-exclusive message branches runs --
        // show_correction_fct -- and asserting the complete string is what
        // proves the other four did not.
        //
        // Both texts are the *translated* strings, not the source keys: the
        // catalogue turns 'Main "guest" user status is incorrect' into 'The
        // main …', so asserting the key would pass against a page that
        // rendered nothing at all.
        expect(c13yPanelText($html))
            ->toBe(
                'Anomaly Correction'
                . ' The main "guest" user status is incorrect'
                . ' Automatic correction'
            );

        // The ignore branch: show_ignore_msg true and can_select false, the
        // only other state this anomaly can reach. Driven through the
        // server's own documented field names rather than the form's submit
        // button -- see this file's trailing note on why those differ.
        $ignored = H::adminPost($page, '/admin.php', [
            'c13y_submit_ignore' => '1',
            'c13y_selection' => [$anomalyId],
        ]);
        expect($ignored['status'])->toBe(200);

        // show_ignore_msg's two sentences now, and show_correction_fct's
        // text gone -- the branch swap is the whole point.
        expect(c13yPanelText($ignored['body']))
            ->toBe(
                'Anomaly Correction'
                . ' The main "guest" user status is incorrect'
                . ' The anomaly will be ignored until next application version'
                . ' Corrected anomaly will no longer be ignored'
            );

        // can_select is false once ignored, so the checkbox that carried the
        // id is gone with it.
        expect($ignored['body'])
            ->not->toContain('name="c13y_selection[]"');

        // can_select is false now, so the checkbox and its empty first-cell
        // label are gone -- which is why the label list above is 2 long, not
        // 3, and why the id no longer reaches the markup four times.
        expect($ignored['body'])
            ->not->toContain('name="c13y_selection[]"');
    } finally {
        H::dbQuery(
            $db,
            "UPDATE user_infos SET status = '" . H::dbEscape($db, $original) . "' WHERE user_id = 2"
        );
        // The ignore submit above persists to integrity_ignored_anomalies,
        // keyed by the running version, and an ignored anomaly is suppressed
        // on every later check -- so without this the panel simply stops
        // rendering and this test fails on its own second run. The fixture
        // ships this table empty.
        H::dbQuery($db, 'DELETE FROM integrity_ignored_anomalies');
        H::dbClose($db);
        H::markSharedSessionDirty();
    }
});

/*
 * Why the ignore branch above is driven by a direct POST of
 * `c13y_submit_ignore` rather than by clicking the form's own button:
 *
 * check_integrity.latte renders its two action buttons as
 * `name="Apply selected corrections"` and `name="Ignore selected anomalies"`,
 * but C13yTreatmentRequest::fromArray() looks for `c13y_submit_correction`
 * and `c13y_submit_ignore`, and nothing anywhere sets those -- not the
 * template, not check_integrity.ts. So both buttons submit the form, the
 * request resolves `mode` to null, and the page simply re-renders unchanged.
 *
 * This is inherited, not a Latte-conversion regression, and not something
 * P58 introduced: the pre-conversion check_integrity.tpl used the same two
 * button names with no hidden fields alongside them, and the pre-DTO PHP it
 * ran against already read `$_POST['c13y_submit_correction']`. It has simply
 * never been exercised end-to-end -- the Integration tests set $_POST
 * directly (CheckIntegrityTest:222/266), which is exactly why the mismatch
 * survived.
 *
 * Left alone deliberately. P58 is type and expression work, and making these
 * buttons functional is a behaviour change that deserves its own decision.
 */
