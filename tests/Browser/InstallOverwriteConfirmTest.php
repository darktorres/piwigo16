<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

// install.ts's overwrite-confirm branch: when the database the operator
// typed already holds a Piwigo installation, the check-db response carries
// `hasExistingInstall: true` and a fresh overwrite token, and the client
// reveals the warning row and writes that token into the hidden field. When
// it does not, the row hides and the confirmation checkbox is cleared.
//
// It had no coverage. InstallTest reaches the install form by wiping the
// database, which by construction leaves nothing to overwrite -- so the
// `true` side of this branch is unreachable from there.
//
// Rendering the form does NOT require a wipe, though: InstallWizard::boot()
// gates only on the `local/.installed.<header>` stamp. Moving that aside
// alone gives a live install form pointed at the suite's own database.
//
// One thing that database does not have: `migration_versions`.
// InstallSchemaDropper::hasExistingInstall() keys on exactly that table --
// deliberately, per its own docblock, because a generically-named `users`
// or `config` could belong to a coincidentally-shared database. But the
// fixture is loaded from a SQL dump rather than by running migrations, so
// it never gets one, and the check honestly answers `false` for a database
// that plainly does hold a Piwigo installation. Seeded here for the
// duration, and dropped again, so the branch sees the state a real install
// would present.

it('reveals the overwrite warning for a database that already has Piwigo', function (): void {
    $projectRoot = dirname(__DIR__, 2);
    // Same literal InstallTest uses: Env::testModeInstalledStamp() resolves
    // to '.installed.<header>' in test mode, and this suite's header is
    // 'test'.
    $stampPath = $projectRoot . '/local/.installed.test';
    $stampBackupPath = $stampPath . '.overwrite-test-backup';

    $stampMoved = false;
    if (file_exists($stampPath)) {
        if (! rename($stampPath, $stampBackupPath)) {
            throw new RuntimeException("Couldn't move {$stampPath} out of the way");
        }
        $stampMoved = true;
    }

    // Only the name matters to the check (`in_array` over the introspected
    // table names), so a one-column table is enough. Created only if it is
    // genuinely absent, so a database that has a real one is left alone.
    $db = H::connect();
    $seededMigrations = H::dbFetchAssoc(
        $db,
        "SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'migration_versions'"
    );
    $needsSeed = (int) ($seededMigrations['n'] ?? 0) === 0;
    if ($needsSeed) {
        H::dbQuery($db, 'CREATE TABLE migration_versions (version VARCHAR(191) NOT NULL PRIMARY KEY)');
    }

    try {
        $page = H::visitPwg($this, '/install.php');
        H::assertNoServerErrors($page, 'install page for the overwrite branch');
        $page->assertPresent('input[name="dbname"]');

        // Server-rendered with no credentials yet: the row is hidden.
        $page->assertMissing('#overwrite-confirm-row');

        // Point it at the database this suite actually runs on, which does
        // hold a Piwigo installation.
        $page = $page
            ->fill('dbhost', (string) getenv('PIWIGO_DB_HOST'))
            ->fill('dbuser', (string) getenv('PIWIGO_DB_USER'))
            ->fill('dbpasswd', (string) getenv('PIWIGO_DB_PASSWORD'))
            ->fill('dbname', (string) getenv('PIWIGO_DB_BASE'));

        // The check is debounced 500ms behind a blur, then goes to the
        // server, so poll rather than time it.
        $page->script(
            "document.getElementById('dbname').dispatchEvent(new Event('blur'))"
        );

        $timeoutMs = 15000;
        $page->script(<<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + {$timeoutMs};
            const check = () => {
                const row = document.getElementById('overwrite-confirm-row');
                if (row !== null && !row.classList.contains('install-hidden-row')) {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    const status = document.getElementById('db-check-status');
                    return reject(new Error(
                        'Timed out waiting for the overwrite warning; db-check said: ' +
                        (status === null ? '(nothing)' : status.textContent)
                    ));
                }
                setTimeout(check, 150);
            };
            check();
        })
        JS);

        /** @var array{token: string, status: string, checked: bool} $revealed */
        $revealed = $page->script(<<<'JS'
        (() => {
            const token = document.getElementById('overwrite_token');
            const status = document.getElementById('db-check-status');
            const confirm = document.getElementById('confirm_overwrite');

            return {
                token: token === null ? '' : token.value,
                status: status === null ? '' : status.textContent.trim(),
                checked: confirm !== null && confirm.checked,
            };
        })()
        JS);

        // The token is minted per response and written into the hidden
        // field every time one arrives -- the eventual real submit is
        // matched against whichever is current.
        expect($revealed['token'])->not->toBe('');
        expect($revealed['status'])->not->toBe('');

        // Now break the credentials. The row must hide again and the
        // confirmation must be cleared, so a stale tick cannot ride along
        // with a submit aimed at a different database.
        $page->script(
            "document.getElementById('confirm_overwrite').checked = true"
        );
        $page = $page->fill('dbname', 'no-such-database-' . uniqid());
        $page->script(
            "document.getElementById('dbname').dispatchEvent(new Event('blur'))"
        );

        $page->script(<<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + {$timeoutMs};
            const check = () => {
                const row = document.getElementById('overwrite-confirm-row');
                if (row !== null && row.classList.contains('install-hidden-row')) {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    return reject(new Error('Timed out waiting for the overwrite warning to hide'));
                }
                setTimeout(check, 150);
            };
            check();
        })
        JS);

        /** @var bool $stillChecked */
        $stillChecked = $page->script(
            "document.getElementById('confirm_overwrite').checked"
        );

        expect($stillChecked)
            ->toBeFalse();

        $page->assertNoJavaScriptErrors();
    } finally {
        if ($needsSeed) {
            H::dbQuery($db, 'DROP TABLE IF EXISTS migration_versions');
        }
        H::dbClose($db);

        // Unconditional, unlike InstallTest's own restore: that test can
        // find a fresh stamp waiting because performInstall() touches one
        // on success. Nothing here installs anything, so the stamp is only
        // ever coming back from the backup.
        if ($stampMoved && ! rename($stampBackupPath, $stampPath)) {
            throw new RuntimeException("Couldn't restore {$stampPath} -- every other route now thinks Piwigo isn't installed");
        }
    }
})->group('install-flow');
