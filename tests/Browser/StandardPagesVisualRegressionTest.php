<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use PHPUnit\Framework\ExpectationFailedException;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P52-E/F verification gap: `standard_pages` (identification/register/
 * password/profile) has real structural coverage via
 * `GoldenHtmlSnapshotTest.php`'s `standard-pages-*` fixtures, but zero
 * pixel coverage -- the main `VisualRegressionTest.php` suite never
 * renders it at all (its shared route table only ever exercises the
 * `default` theme's own identification/register/password/profile
 * views). That gap matters directly for the upcoming `@layer` wrap:
 * `standard_pages` has 11 real skins (vs. admin's 2), each a self-
 * contained light+dark pair, and nothing today would catch a wrong
 * generic-vs-specific precedence the way `admin-cat-list`'s real VR
 * failure caught one during the admin campaign.
 *
 * A small, curated subset, not all 11 skins x both modes x 4 pages --
 * enough to exercise every real axis at least once (both auth patterns,
 * both `.light`/`.dark` states, a second skin proving skin-selection
 * itself renders correctly) without 44 near-duplicate baselines.
 *
 * Mechanism mirrors `GoldenHtmlSnapshotTest.php`'s own
 * `goldenHtmlCapturesStandardPages()`: `Template::setTheme()`'s
 * `standard_pages` fallback only fires for a non-'default' `CurrentUser`
 * theme, and the fixture has no second real gallery theme installed, so
 * `golden_html_test` (a `themeconf.inc.php`-only stub) exists purely to
 * give it something real to swap away from `default` with before
 * `Template` reroutes it to the real `themes/standard_pages` directory.
 *
 * `.light`/`.dark` is a client-side toggle (`standard_pages.ts`, a
 * `mode` cookie falling back to `prefers-color-scheme`) -- driven here
 * via pest-plugin-browser's own `colorScheme` context option
 * (`->inDarkMode()`/`->inLightMode()`'s underlying option key), not a
 * cookie: a fresh context with no `mode` cookie set genuinely falls
 * back to the OS/emulated color-scheme preference, which is exactly
 * what this option controls.
 *
 * The 3 `profile.php` captures mutate a *different* user row than the
 * one that's actually logged in -- see standardPagesCaptureAuthenticated()'s
 * own comment for the real, confirmed reason (an empty `themes` DB
 * table in this fixture, unrelated to session caching). A first attempt
 * that mutated fixture_admin's own row instead silently baked the
 * wrong (plain, unstyled) page into the "baseline" -- no error, no
 * false test failure, just wrong pixels, only caught by decoding and
 * visually inspecting the captured snapshot rather than trusting a
 * green "Snapshot created" result.
 *
 * MUST run in isolation, same reason as VisualRegressionTest.php:
 * bundling with CRUD-mutating tests drifts the sidebar's live counts
 * (irrelevant to these routes, but this file follows the same
 * convention as every other VR file in this suite regardless).
 *
 * To (re)generate baselines: `composer test:visual:update` (always a
 * full run, no `--filter` -- see .claude/hooks/check-tool-invocation-
 * guardrails.sh; a raw `vendor/bin/pest` invocation is blocked outright).
 */
function standardPagesVisit(object $test, string $path, string $colorScheme): Webpage|PendingAwaitablePage|AwaitableWebpage
{
    $options = [
        ...H::testModeOptions(),
        'colorScheme' => $colorScheme,
    ];

    // @phpstan-ignore method.notFound
    $result = $test->visit(H::baseUrl() . $path, $options);

    if (
        ! $result instanceof Webpage
        && ! $result instanceof PendingAwaitablePage
        && ! $result instanceof AwaitableWebpage
    ) {
        throw new ExpectationFailedException(
            'visit() did not return a Webpage/PendingAwaitablePage/AwaitableWebpage — '
            . 'pest-plugin-browser may have changed its return type.'
        );
    }

    H::assertNoServerErrors($result, $path);

    return $result;
}

function standardPagesCaptureAnonymous(object $test, string $path, string $colorScheme, ?string $skin = null): void
{
    $guestUserId = 2;
    $previousTheme = H::userTheme($guestUserId);
    expect($previousTheme)
        ->not->toBeNull("fixture guest user (id {$guestUserId}) has no user_infos row");

    $configSnapshot = $skin !== null ? H::snapshotConfig(['standard_pages_selected_skin']) : [];

    H::setUserTheme($guestUserId, 'golden_html_test');
    if ($skin !== null) {
        H::setConfigValue('standard_pages_selected_skin', H::jsonEncode($skin));
    }

    try {
        $page = standardPagesVisit($test, $path, $colorScheme);
        $page->assertScreenshotMatches();
    } finally {
        H::setUserTheme($guestUserId, $previousTheme ?? 'default');
        if ($skin !== null) {
            H::restoreConfig($configSnapshot);
        }
    }
}

it('renders standard_pages identification in light mode', function (): void {
    standardPagesCaptureAnonymous($this, '/identification.php', 'light');
})->group('visual-regression');

it('renders standard_pages identification in dark mode', function (): void {
    standardPagesCaptureAnonymous($this, '/identification.php', 'dark');
})->group('visual-regression');

it('renders standard_pages identification under the cobalt skin', function (): void {
    standardPagesCaptureAnonymous($this, '/identification.php', 'light', 'cobalt');
})->group('visual-regression');

it('renders standard_pages register in light mode', function (): void {
    standardPagesCaptureAnonymous($this, '/register.php', 'light');
})->group('visual-regression');

it('renders standard_pages password in light mode', function (): void {
    standardPagesCaptureAnonymous($this, '/password.php', 'light');
})->group('visual-regression');

/**
 * profile.php is auth-required, so it needs a logged-in user -- but the
 * theme mutation still targets `CurrentConfig::defaultUserId` (2, same
 * as the anonymous guest row standardPagesCaptureAnonymous() already
 * mutates), NOT fixture_admin's own user_infos row (1), for a genuine,
 * confirmed reason found live while first writing this test:
 * `UserService::buildUser()` validates a user's own theme via a
 * `LEFT JOIN themes t ON t.id = ui.theme` (UserRepository::
 * fetchUserInfosWithThemeName()) and silently substitutes
 * `getDefaultTheme()`'s result whenever that join finds no row -- and
 * this fixture's `themes` table is completely empty, so that join
 * *always* misses, for every user, every theme. `getDefaultTheme()`
 * itself reads `defaultUserId`'s own row (`UserService::
 * getDefaultUserInfo()`), which defaults to 2 -- the same id as the
 * fixture's guest user, purely coincidentally. Net effect: in this
 * fixture, an individual user's own `user_infos.theme` is *never*
 * actually consulted -- confirmed live via `X-Piwigo-Env: test` +
 * Playwright (see feedback_playwright_needs_xpiwigoenv_test_header.md):
 * setting fixture_admin's own theme had zero effect on `/profile.php`'s
 * real rendered `<link>` tags, while setting user_id 2's did, even
 * while logged in as fixture_admin. This is also, incidentally, why the
 * pre-existing `standard-pages-profile.html` golden-HTML fixture
 * (`GoldenHtmlSnapshotTest.php`'s `goldenHtmlCapturesStandardPages()`,
 * which mutates fixture_admin's own row) has never actually captured
 * `standard_pages` markup despite its name -- a real, separate,
 * pre-existing bug in that already-passing fixture, out of scope here
 * (not introduced by this file, and fixing it means correcting an
 * already-committed baseline, not building a new one).
 */
function standardPagesCaptureAuthenticated(object $test, string $colorScheme, ?string $skin = null): void
{
    $defaultUserId = 2;
    $previousTheme = H::userTheme($defaultUserId);
    expect($previousTheme)
        ->not->toBeNull("fixture default user (id {$defaultUserId}) has no user_infos row");

    $configSnapshot = $skin !== null ? H::snapshotConfig(['standard_pages_selected_skin']) : [];

    H::setUserTheme($defaultUserId, 'golden_html_test');
    if ($skin !== null) {
        H::setConfigValue('standard_pages_selected_skin', H::jsonEncode($skin));
    }

    try {
        // `standard_pages/template/profile.latte` has no logout link at
        // all (a genuinely minimal template) -- H::asAdmin()'s own
        // default `a[href*="act=logout"]` login-succeeded check would
        // fail here for that reason alone, unrelated to whether auth
        // actually worked. `#account-section` is this template's own
        // always-present profile-page marker, serving the same "did we
        // really land on the authenticated page, not a login redirect"
        // purpose.
        $page = H::asAdmin($test, '/profile.php', $colorScheme, loggedInSelector: '#account-section');
        $page->assertScreenshotMatches();
    } finally {
        H::setUserTheme($defaultUserId, $previousTheme ?? 'default');
        if ($skin !== null) {
            H::restoreConfig($configSnapshot);
        }
    }
}

it('renders standard_pages profile in light mode', function (): void {
    standardPagesCaptureAuthenticated($this, 'light');
})->group('visual-regression');

it('renders standard_pages profile in dark mode', function (): void {
    standardPagesCaptureAuthenticated($this, 'dark');
})->group('visual-regression');

it('renders standard_pages profile under the cobalt skin', function (): void {
    standardPagesCaptureAuthenticated($this, 'light', 'cobalt');
})->group('visual-regression');
