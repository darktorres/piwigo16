<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Install-flow regression test. Tagged 'install-flow' (composer
 * test:install) and given its own dedicated CI job (a fresh mysql
 * service container starts with an empty piwigo_test DB by
 * construction, and that job deliberately skips the fixture-provisioning
 * + `touch local/.installed.test` steps every other Browser-suite job
 * runs), so this actually exercises the install flow: every other job
 * (and this test, run via plain composer test:browser) pre-marks the app
 * as installed so the ~70 *other* tests (which assume an
 * already-installed, fixture-loaded app) work. The markTestSkipped()
 * call below is a defensive fallback (this test still shouldn't corrupt
 * an already-installed environment if run there by mistake), not the
 * mechanism this test relies on to actually run. Run locally against a
 * genuinely fresh DB + `rm local/.installed.test` to exercise this by
 * hand -- see tests/Browser/RegenerateFixtureTest.php for the same
 * install-form flow used to build the committed fixture.
 *
 * install.latte's `send_credentials_by_mail` checkbox is `checked="checked"`
 * by default, so a real browser submits it. That field makes
 * `InstallWizard::boot()` call `MailService::mail()` -> Symfony
 * Mailer's native transport (no `smtp_host` configured on a fresh
 * install) -> PHP's `mail()` -> a real synchronous `/usr/sbin/sendmail`
 * invocation -- which blocks for 60-120+s in an environment with no
 * outbound SMTP egress. This test unchecks that box before submit, the
 * same way `newsletter_subscribe` already is -- it has no reason to
 * depend on real mail delivery succeeding or even being fast.
 * `tests/Browser/RegenerateFixtureTest.php` is unaffected: it POSTs an
 * explicit field array, not a real filled-in browser form, so it never
 * sends this field either.
 */
it('completes a fresh install end-to-end', function (): void {
    $page = H::visitPwg($this, '/install.php');

    if (str_contains($page->content(), 'Congratulations') || ! str_contains($page->content(), 'Installation')) {
        Assert::markTestSkipped('Piwigo is already installed — remove local/.installed.test to exercise this flow.');
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
        // checked="checked" by default in install.latte -- triggers a real,
        // slow MailService::mail() send otherwise. See this file's own
        // top docblock.
        ->uncheck('send_credentials_by_mail');

    // Submitting the install form runs the real schema creation + config
    // seeding + admin user creation (~4-5s server-side) -- both
    // pest-plugin-browser's own ~1s-per-attempt retry-wrap AND Playwright's
    // own default ~5s action timeout are too short for that, and
    // Webpage::click() itself passes no timeout through to Playwright's own
    // action. clickWithTimeout() (see its own docblock) is the one path
    // that actually accepts one.
    H::clickWithTimeout($page, 'install');

    $page->assertSee('Congratulations');
    $page->assertSeeLink('Visit the gallery');
    H::assertNoServerErrors($page, 'install success page');
})->group('install-flow');
