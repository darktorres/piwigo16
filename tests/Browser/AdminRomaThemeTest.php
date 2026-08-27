<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

// `roma` is one of three real, user-selectable admin themes
// (AdminShell.php's own `['roma', 'clear']`, chosen via
// PreferencesService::getAdminThemePref() with CurrentConfig::$adminTheme as
// the site-wide fallback) and it is the only one no snapshot covers: 53
// golden fixtures render admin/default and 52 render admin/clear, none
// render roma. It also carries the largest jQuery-UI CSS surface of the
// three -- 51 `ui-*` rules against default's 21, including 27 for
// `#ui-datepicker-div` alone -- so it is precisely where a lost marker class
// goes unnoticed.
//
// It cannot be covered by the shared route table. The theme is a persisted
// preference, and the only URL affordance (AdminShell's `changeThemePresent`)
// *toggles* roma<->clear rather than selecting one, writes the preference,
// and 302-redirects -- three reasons a single-GET snapshot cannot use it.
// Hence a Browser test that sets the preference outright.
//
// `hasDatepicker` is asserted here rather than in golden-html for a
// structural reason: golden captures the raw HTTP body, and that class is
// added client-side by the datepicker when it attaches. It is invisible to
// golden-html by construction (the admin-history fixture contains zero
// occurrences of it), so a JS-executing test is the only instrument that can
// see it at all.

it('renders the roma admin theme with its jQuery-UI marker classes intact', function (): void {
    // Preferences are persisted to user_infos.preferences (UserRepository::
    // savePreferences(), a real UPDATE keyed by user id), not to the session
    // -- so leaving admin_theme=roma behind would render every later admin
    // test under roma and corrupt the golden and visual baselines. Snapshot
    // and restore the whole JSON blob rather than unsetting one key, so the
    // row comes back exactly as it was even if an assertion throws.
    $db = H::connect();
    $before = H::dbFetchAssoc($db, 'SELECT preferences FROM user_infos WHERE user_id = 1');
    $original = is_string($before['preferences'] ?? null) ? $before['preferences'] : '{}';

    try {
        $page = H::loginAsAdmin($this);
        H::setSessionPreference($page, [
            'param' => 'admin_theme',
            'value' => 'roma',
        ]);

        $page = H::navigateOk($page, '/admin.php?page=history');

        // The theme really is roma, not the default -- without this the rest
        // would pass under whatever theme happened to be active.
        $page->assertPresent('link[href*="themes/admin/roma/"]');

        // The marker class the last jQuery-removal attempt dropped. Every
        // admin theme styles the attached field through it and nothing else,
        // roma included, so losing it silently falls back to user-agent
        // styling. Added on attach, so poll rather than race the ready
        // handler.
        $timeoutMs = 5000;
        $page->script(<<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + {$timeoutMs};
            const check = () => {
                if (document.querySelector('input.hasDatepicker') !== null) {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    return reject(new Error('Timed out waiting for hasDatepicker to be attached'));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);

        $page->assertPresent('input.hasDatepicker');
        $page->assertNoJavaScriptErrors();
    } finally {
        H::dbQuery(
            $db,
            "UPDATE user_infos SET preferences = '" . H::dbEscape($db, $original) . "' WHERE user_id = 1"
        );
        H::dbClose($db);
        H::markSharedSessionDirty();
    }
});
