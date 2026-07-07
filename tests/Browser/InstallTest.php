<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Install-flow regression test — opt-in in spirit (skipped once
 * local/.installed.test already exists, which is the common case since
 * Contract/other Browser tests run against an already-installed fixture).
 * Run against a genuinely fresh DB + `rm local/.installed.test` first to
 * exercise this for real — see tests/Browser/RegenerateFixtureTest.php for
 * the same install-form flow used to build the committed fixture.
 */
it('completes a fresh install end-to-end', function (): void {
    $page = H::visitPwg($this, '/install.php');

    if (str_contains($page->content(), 'Congratulations') || !str_contains($page->content(), 'Installation')) {
        test()->markTestSkipped('Piwigo is already installed — remove local/.installed.test to exercise this flow.');
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
});
