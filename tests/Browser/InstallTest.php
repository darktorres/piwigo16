<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Install-flow regression test. Tagged 'install-flow' (composer
 * test:install), given its own dedicated CI job (a fresh mysql service
 * container starts with an empty piwigo_test DB by construction, and
 * that job deliberately skips the fixture-provisioning +
 * `touch local/.installed.test` steps every other Browser-suite job
 * runs), and runnable locally too: this test wipes PIWIGO_DB_BASE to a
 * genuinely empty database itself (via tools/wipe-test-database.sh, the
 * same DROP+CREATE shape as IntegrationTestCase::dropAndCreateDatabase())
 * and moves local/.installed.test out of the way, so it never depends on
 * the environment already being in a fresh-install-ready state the way
 * CI's own dedicated job is. Every other Browser test assumes an
 * already-installed, fixture-loaded app, so this restores that in a
 * `finally` block regardless of outcome -- via tools/reimport-fixture.sh,
 * the same script composer test:browser/test:visual already run as their
 * own preceding step -- rather than leaving the DB in whatever state a
 * bare install run left it in. See tests/Browser/RegenerateFixtureTest.php
 * for the same install-form flow used to build the committed fixture.
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
    $projectRoot = dirname(__DIR__, 2);
    $flagPath = $projectRoot . '/local/.installed.test';
    $flagBackupPath = $flagPath . '.install-test-backup';

    $flagMoved = false;
    if (file_exists($flagPath)) {
        if (! rename($flagPath, $flagBackupPath)) {
            throw new RuntimeException("Couldn't move {$flagPath} out of the way");
        }
        $flagMoved = true;
    }

    $wipeOutput = [];
    $wipeExitCode = 0;
    exec('cd ' . escapeshellarg($projectRoot) . ' && bash tools/wipe-test-database.sh 2>&1', $wipeOutput, $wipeExitCode);
    if ($wipeExitCode !== 0) {
        // The install form below never ran -- restore the flag before
        // failing, rather than leaving every other route thinking
        // Piwigo isn't installed for the rest of the suite.
        if ($flagMoved) {
            rename($flagBackupPath, $flagPath);
        }

        throw new RuntimeException("tools/wipe-test-database.sh failed:\n" . implode("\n", $wipeOutput));
    }

    try {
        $page = H::visitPwg($this, '/install.php');

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
    } finally {
        // InstallWizard::performInstall() itself touch()es a fresh
        // local/.installed.test on success, at the very end of the method
        // (after schema migration, config.sql seeding, extension
        // activation, the sites row, and webmaster/guest user creation all
        // succeeded) -- the real one, not our backup, so only fall back to
        // restoring the backup (or creating a fresh flag, if none existed
        // before) when that never happened: an earlier assertion failed, or
        // the install itself errored, before performInstall() reached that
        // point.
        if (! file_exists($flagPath)) {
            if ($flagMoved) {
                if (! rename($flagBackupPath, $flagPath)) {
                    throw new RuntimeException("Couldn't restore {$flagPath} -- every other route now thinks Piwigo isn't installed");
                }
            } elseif (! touch($flagPath)) {
                throw new RuntimeException("Couldn't create {$flagPath} -- every other route now thinks Piwigo isn't installed");
            }
        } elseif ($flagMoved && file_exists($flagBackupPath)) {
            unlink($flagBackupPath);
        }

        // Restores the fixture-loaded DB + fixture photos/caches every
        // other Browser test assumes, regardless of whether the install
        // above succeeded.
        $reimportOutput = [];
        $reimportExitCode = 0;
        exec('cd ' . escapeshellarg($projectRoot) . ' && bash tools/reimport-fixture.sh 2>&1', $reimportOutput, $reimportExitCode);
        if ($reimportExitCode !== 0) {
            throw new RuntimeException("tools/reimport-fixture.sh failed after the install test:\n" . implode("\n", $reimportOutput));
        }
    }
})->group('install-flow');
