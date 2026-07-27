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
 * self-skipping -- docs/PLAN.md gap-closure, 2026-07-23:
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
 *    InstallWizard::boot()'s own credential seeding (needed so a
 *    *submitted* form's real credentials win over stale ones; now
 *    `Piwigo\Db\DbCredentials::seed(['PIWIGO_DB_USER' => $this->dbuser, ...])`,
 *    Config generic-accessor removal) runs with `$this->dbuser === ''`
 *    (no $_POST yet), clobbering whatever valid credentials were already
 *    loaded. Widened that catch to the full Doctrine\DBAL\Exception
 *    interface -- see that file's own docblock.
 *
 * 2. FIXED 2026-07-25 -- with (1) fixed, the install genuinely runs and
 *    succeeds server-side every time, but a real Chromium browser's
 *    click('install') never resolved even past a 30s+ timeout, while
 *    `curl` against a hand-crafted equivalent POST consistently returned
 *    in 3.5-4.5s. That curl comparison was the misleading part: it's
 *    *not* an equivalent request. The previous investigation (correctly)
 *    ruled out Accept-Language, IPv4/v6, HTTP/2, keep-alive reuse, and
 *    Apache worker exhaustion, and concluded the document response itself
 *    must be fine and something client-side must be hanging instead --
 *    reasonable given curl's timing, but wrong. Re-root-caused via the
 *    Playwright MCP tools with real network-event timestamps: the POST's
 *    own `framenavigated` event landed ~123s after the request was sent,
 *    with *zero* subresources still pending once it did -- the document
 *    response itself was what took 123s, not anything it went on to load.
 *    The actual difference from curl: install.tpl's `send_credentials_by_mail`
 *    checkbox is `checked="checked"` by default, so a real browser submits
 *    it and a hand-crafted curl POST (which only sends fields you name
 *    explicitly) silently didn't. That field makes
 *    `InstallWizard::boot()` call `MailService::mail()` -> Symfony
 *    Mailer's native transport (no `smtp_host` configured on a fresh
 *    install) -> PHP's `mail()` -> a real synchronous `/usr/sbin/sendmail`
 *    invocation -- which blocks for 60-120+s in this sandbox (no outbound
 *    SMTP egress; confirmed directly, independent of this app entirely:
 *    `printf ... | sendmail -t -i` to `admin@example.test` took 60.1s
 *    wall-clock to give up). Fixed here by unchecking that box before
 *    submit, the same way `newsletter_subscribe` already is -- this test
 *    has no reason to depend on real mail delivery succeeding or even
 *    being fast. `tests/Browser/RegenerateFixtureTest.php` was never
 *    affected: it POSTs an explicit field array (like curl), not a real
 *    filled-in browser form, so it never sent this field either.
 *    NOT fixed here, deliberately out of scope: the same hang is a real
 *    risk for an actual first-time admin too, on any install where mail
 *    delivery is slow or the checkbox is left checked (the default) --
 *    that's an application-level synchronous-mail-on-request-thread
 *    problem, not a test problem, and needs its own decision (e.g. an
 *    async transport once Messenger exists -- see docs/REFERENCE.md's
 *    own note that Messenger isn't built yet).
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
        ->uncheck('newsletter_subscribe')
        // checked="checked" by default in install.tpl -- triggers a real
        // MailService::mail() send otherwise. See item 2 of this file's
        // own top docblock for why that's what was actually hanging.
        ->uncheck('send_credentials_by_mail');

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
