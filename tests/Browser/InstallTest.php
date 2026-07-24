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
        ->click('install');

    $page->assertSee('Congratulations');
    $page->assertSeeLink('Visit the gallery');
    H::assertNoServerErrors($page, 'install success page');
})->group('install-flow');
