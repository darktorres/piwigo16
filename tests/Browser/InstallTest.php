<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Install-flow regression test. Tagged 'install-flow' (composer
 * test:install) and given its own dedicated CI job (a fresh mysql
 * service container starts with an empty piwigo_test DB by
 * construction, and that job deliberately skips the fixture-provisioning
 * + `touch local/.installed.test` steps every other Browser-suite job
 * runs) specifically so this runs for real instead of permanently
 * self-skipping -- docs/PLAN-REPLAY-AUDIT.md gap-closure, 2026-07-23:
 * every other job (and this test, run via plain composer test:browser)
 * pre-marks the app as installed so the ~70 *other* tests (which assume
 * an already-installed, fixture-loaded app) work, which meant this test
 * had never actually exercised the install flow anywhere, ever, despite
 * reporting a reassuring "1 skipped" instead of a loud gap. The
 * markTestSkipped() call below stays as a real defensive fallback (this
 * test still shouldn't corrupt an already-installed environment if run
 * there by mistake), not the fix itself. Run locally against a
 * genuinely fresh DB + `rm local/.installed.test` to exercise this by
 * hand -- see tests/Browser/RegenerateFixtureTest.php for the same
 * install-form flow used to build the committed fixture.
 *
 * 2026-07-24: actually running this against a genuinely fresh DB (the
 * whole point of the fix above) surfaced two more real, previously-
 * invisible issues, one fixed, one still open:
 *
 * 1. FIXED -- Template.php's `data_dir_checked` write (its own try/catch
 *    only caught TableNotFoundException) crashed with an uncaught
 *    Doctrine\DBAL\Exception\ConnectionException instead: on the *first*
 *    GET to install.php, before the form is ever submitted,
 *    InstallWizard::boot()'s own `Config::override('db_user',
 *    $this->dbuser)` (needed so a *submitted* form's real credentials
 *    win over stale ones) runs with `$this->dbuser === ''` (no $_POST
 *    yet), clobbering whatever valid credentials were already loaded.
 *    Widened that catch to the full Doctrine\DBAL\Exception interface --
 *    see that file's own docblock.
 *
 * 2. STILL OPEN -- with (1) fixed, the install genuinely runs and
 *    succeeds server-side every time (verified repeatedly via direct DB
 *    inspection: schema + config + admin user all correct; also via a
 *    saved screenshot showing "Congratulations"). But a real Chromium
 *    browser's click('install') never resolves. Root-caused via the
 *    Playwright MCP tools (bypassing pest-plugin-browser entirely) to a
 *    specific, narrow place: the click itself succeeds immediately
 *    ("click action done"), then Playwright explicitly "waits for
 *    scheduled navigations to finish" -- and that wait never completes,
 *    even with an explicit 30s+ timeout. `curl` reproduces the identical
 *    request (same headers, cookies, Accept-Language, method) in a
 *    consistent 3.5-4.5s every time. This means the HTML response itself
 *    is fine and fast; something the resulting success page loads
 *    client-side (a script/stylesheet/image) most likely never resolves,
 *    so the browser's own `load` event never fires. Ruled out with
 *    direct evidence, not guesses: the browser's default Accept-Language
 *    (Portuguese) -- reproduced identically via curl, completes fine;
 *    IPv4 vs IPv6 (forced 127.0.0.1, no change); HTTP/2 (Apache doesn't
 *    negotiate it here at all); keep-alive connection reuse (tested
 *    directly, no hang); Apache worker exhaustion (11 of 150 workers in
 *    use). Next step for whoever picks this up: inspect exactly what the
 *    install-success page (install.tpl's post-submit render) loads
 *    client-side, with real browser devtools open (the MCP browser
 *    session itself got wedged mid-investigation and couldn't be used to
 *    finish the job -- even browser_close() timed out).
 */
it('completes a fresh install end-to-end', function (): void {
    $page = H::visitPwg($this, '/install.php');

    if (str_contains($page->content(), 'Congratulations') || !str_contains($page->content(), 'Installation')) {
        \PHPUnit\Framework\Assert::markTestSkipped('Piwigo is already installed — remove local/.installed.test to exercise this flow.');
    }

    H::assertNoServerErrors($page, 'install page initial render');
    $page->assertSee('Installation');
    $page->assertPresent('input[name="dbhost"]');
    $page->assertPresent('input[name="dbuser"]');
    $page->assertPresent('input[name="dbpasswd"]');
    $page->assertPresent('input[name="dbname"]');

    $page = $page
        ->fill('dbhost', (string) getenv('PIWIGO_DB_HOST'))
        ->fill('dbuser', (string) getenv('PIWIGO_DB_USER'))
        ->fill('dbpasswd', (string) getenv('PIWIGO_DB_PASSWORD'))
        ->fill('dbname', (string) getenv('PIWIGO_DB_BASE'))
        ->fill('admin_name', 'admin')
        ->fill('admin_pass1', 'p4ssword!')
        ->fill('admin_pass2', 'p4ssword!')
        ->fill('admin_mail', 'admin@example.test')
        ->uncheck('newsletter_subscribe');

    // Submitting the install form runs the real schema creation + config
    // seeding + admin user creation (~4-5s server-side, confirmed via a
    // direct curl timing) -- both pest-plugin-browser's own ~1s-per-attempt
    // retry-wrap AND Playwright's own default ~5s action timeout are too
    // short for that. Confirmed live in two stages: the wrapped click()
    // failed with "Timeout 5000ms exceeded" even though the install had
    // already genuinely succeeded server-side (verified via both the DB
    // state and the success-page screenshot pest saved); switching to
    // H::rawWebpage()->click() alone (bypassing only the retry-wrap) hit
    // the same 5000ms ceiling again, since Webpage::click() still passes
    // no timeout through to Playwright's own action. clickWithTimeout()
    // (see its own docblock) is the one path that actually accepts one.
    H::clickWithTimeout($page, 'install');

    $page->assertSee('Congratulations');
    $page->assertSeeLink('Visit the gallery');
    H::assertNoServerErrors($page, 'install success page');
})->group('install-flow');
