<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/standard_pages/js/profile.ts --
 * ProfileControllerTest.php only covers the real server-side POST
 * submission path (profile.php's own form handler); this file covers two
 * genuinely client-side-only behaviors the conversion touched: the
 * collapsible profile-section toggle (a manual maxHeight
 * measure-then-collapse, not a CSS transition class), and the live
 * password-mismatch check mirroring the server-side validation.
 *
 * `themes/default/template/profile.latte` and `themes/standard_pages/
 * template/profile.latte` are BOTH real, distinct templates for the same
 * `ProfileView` (Piwigo\Controller\Projection\ProfileView's own
 * docblock) -- the default theme's own file just embeds
 * `$profileContent` (the old-style `profile_content.latte` form), while
 * `standard_pages`'s renders this file's own collapsible-sections markup
 * inline. `Piwigo\Template\ThemeChain::walk()` substitutes
 * `standard_pages` for identification/register/password/profile
 * whenever the resolving theme isn't already `'default'` -- and for
 * *all four* of those pages that resolution always uses the GUEST
 * user's own `user_infos.theme` (id 2 in this fixture), never the
 * actual signed-in viewer's, confirmed live: setting fixture_admin's
 * (id 1) own theme had no effect at all, on either an API-session
 * `H::asAdmin()` login or a real browser form login, and switching users
 * entirely (admin vs. a plain authenticated user) made no difference
 * either -- only the guest row does. StandardPagesFormBehaviourTest.php's
 * own docblock already documents this for register/password/
 * identification ("these pages render under whatever theme the guest
 * user has"); this is the same mechanism reaching a fourth, authenticated
 * page. `themes/golden_html_test` (its own theme.json literally says "so
 * Template::setTheme()'s standard_pages fallback has something real to
 * load before it swaps to themes/standard_pages") is what makes this
 * possible at all -- `loadThemeconf()` needs an existing directory to
 * read before the substitution fires, so a made-up theme name (tried
 * first) silently falls back to `default` and never reaches this
 * template either. Snapshot/restore + `H::markSharedSessionDirty()` the
 * same way that file's own beforeEach/afterEach does.
 *
 * This file has no still-jQuery surface at all -- no widget, no library
 * call -- so it converts in full; nothing needs a "Still jQuery" note.
 */
beforeEach(function (): void {
    $this->previousGuestTheme = H::userTheme(2) ?? 'default';
    H::setUserTheme(2, 'golden_html_test');
    H::markSharedSessionDirty();
});

afterEach(function (): void {
    H::setUserTheme(2, $this->previousGuestTheme);
    H::markSharedSessionDirty();
});

it('opens and closes the preferences section via its own maxHeight toggle', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/profile.php');
    $page->assertPresent("[data-display='preferences-display']");

    // The account section auto-opens 100ms after load (this file's own
    // setTimeout) -- give it time to settle before touching a different
    // section, so this test's own toggle isn't racing that one.
    $page->script('new Promise((resolve) => setTimeout(resolve, 300))');

    $isOpen = static fn (Webpage|PendingAwaitablePage|AwaitableWebpage $page): mixed => $page->script(
        "document.getElementById('preferences-display').classList.contains('open')",
    );

    expect($isOpen($page))
        ->toBeFalse();

    $page->click("[data-display='preferences-display']");
    expect($isOpen($page))
        ->toBeTrue();
    expect($page->script("document.getElementById('preferences-display').style.maxHeight"))
        ->not->toBe('1px');

    $page->click("[data-display='preferences-display']");
    expect($isOpen($page))
        ->toBeFalse();
    expect($page->script("document.getElementById('preferences-display').style.maxHeight"))
        ->toBe('1px');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'profile section toggle');
});

it('shows a live mismatch error between the two new-password fields, and clears it once they match', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/profile.php');
    $page->assertPresent('#password_conf');

    $errorVisible = static fn (Webpage|PendingAwaitablePage|AwaitableWebpage $page): mixed => $page->script(
        "document.getElementById('password_conf').closest('.column-flex').querySelector('.error-message').offsetParent !== null",
    );

    expect($errorVisible($page))
        ->toBeFalse();

    $page->script(
        "document.getElementById('password_new').value = 'secret-one';" .
        "document.getElementById('password_conf').value = 'secret-two';" .
        "document.getElementById('password_conf').dispatchEvent(new Event('keyup', {bubbles: true}))",
    );
    expect($errorVisible($page))
        ->toBeTrue();
    expect($page->script(
        "document.getElementById('password_conf').closest('.column-flex').querySelector('.error-message').textContent",
    ))
        ->toContain('do not match');

    $page->script(
        "document.getElementById('password_conf').value = 'secret-one';" .
        "document.getElementById('password_conf').dispatchEvent(new Event('keyup', {bubbles: true}))",
    );
    expect($errorVisible($page))
        ->toBeFalse();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'profile password mismatch check');
});
