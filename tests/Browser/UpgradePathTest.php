<?php

declare(strict_types=1);

namespace Piwigo\Tests\Browser;

use PHPUnit\Framework\Attributes\Group;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Legacy Coupling Retirement Phase 8, 8b -- covers the real, already-
 * diagnosed bug this sub-phase fixed: upgrade.php/upgrade_feed.php never
 * bridged database.inc.php's $conf['db_host']/['db_user']/['db_password']/
 * ['db_base'] into Config::'s static state, so DbConnection::build()
 * (which UpgradeService::upgradeDbConnect() and UpgradeFeedRunner::run()
 * both call) always attempted an empty-string-credentials connection
 * regardless of the site's real configuration. No test exercised either
 * path with real credentials before this (RegenerateFixtureTest.php only
 * covers install.php).
 *
 * database.inc.php is never written in test mode
 * (InstallWizard::performInstall() gates that write on
 * !Env::testModeIsActive(), confirmed via a real fixture-regen run during
 * 8b's own verification) -- both scripts unconditionally `die()` without
 * it, so this test writes a real one from the same .env.test credentials
 * IntegrationTestCase already uses, and removes it in tearDown() no
 * matter what (a stray file here would silently change every other
 * test's "test mode never touches database.inc.php" assumption).
 *
 * Excluded from the default Browser suite run (composer test:browser
 * passes --exclude-group=fixture-regen but NOT this group -- this test
 * doesn't wipe anything, unlike RegenerateFixtureTest, so it's safe to
 * leave in the default run; the Group attribute exists only so it can be
 * targeted directly with --group=upgrade-path during development).
 */
#[Group('upgrade-path')]
final class UpgradePathTest extends IntegrationTestCase
{
    private string $databaseIncPath = '';

    private bool $wroteDatabaseInc = false;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->requireBaseUrl();
        $this->databaseIncPath = dirname(__DIR__, 2) . '/local/config/database.inc.php';
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->wroteDatabaseInc && file_exists($this->databaseIncPath)) {
            unlink($this->databaseIncPath);
        }
    }

    public function test_upgrade_php_connects_with_real_credentials(): void
    {
        $this->writeRealDatabaseInc();

        $body = $this->get('upgrade.php');

        // Confirmed by a real failing run during 8b's own verification:
        // without the fix, this would be a raw Doctrine\DBAL connection
        // exception (500, empty body under this environment's error
        // display settings) rather than either of these two legitimate
        // application-level outcomes.
        self::assertStringNotContainsStringIgnoringCase('access denied', $body);
        self::assertMatchesRegularExpression(
            '/up to date|start install|Piwigo Upgrade/i',
            $body,
            'upgrade.php must reach real application logic, not fail the DB connection'
        );
    }

    public function test_upgrade_feed_php_connects_with_real_credentials(): void
    {
        $this->writeRealDatabaseInc();

        $body = $this->get('upgrade_feed.php');

        self::assertStringNotContainsStringIgnoringCase('access denied', $body);
        // check_upgrade_feed defaults to false (Config::SCHEMA), so reaching
        // this guard message -- rather than a raw connection exception -- is
        // exactly what a successful connection looks like here.
        self::assertStringContainsString('upgrade feed is not active', $body);
    }

    private function writeRealDatabaseInc(): void
    {
        self::assertFileDoesNotExist(
            $this->databaseIncPath,
            'local/config/database.inc.php already exists -- refusing to overwrite a real file. '
                . 'This test only ever runs against test-mode (database.inc.php is never written there).'
        );

        // upgrade.php specifically requires a literal PHP close tag --
        // strrpos() against $config_file_contents is how it finds where
        // the frozen install/upgrade_X.Y.Z.php scripts' own embedded PHP
        // ends (a historical parsing quirk of the original file format),
        // not just PHP's own optional-closing-tag convention. Built via
        // concatenation below, not written literally -- an unescaped
        // close tag anywhere in this file's own source, even inside a
        // comment or a double-quoted string, would end PHP mode right
        // here, at this file's own parse time.
        $closeTag = '?' . '>';
        $contents = "<?php\n"
            . '$conf[\'db_host\'] = ' . var_export($this->dbHost, true) . ";\n"
            . '$conf[\'db_user\'] = ' . var_export($this->dbUser, true) . ";\n"
            . '$conf[\'db_password\'] = ' . var_export($this->dbPass, true) . ";\n"
            . '$conf[\'db_base\'] = ' . var_export($this->dbName, true) . ";\n"
            . '$prefixeTable = ' . var_export($this->dbPrefix, true) . ";\n"
            . '$conf[\'dblayer\'] = \'mysqli\';' . "\n"
            . $closeTag . "\n";

        self::assertNotFalse(file_put_contents($this->databaseIncPath, $contents));
        $this->wroteDatabaseInc = true;
    }

    private function get(string $scriptName): string
    {
        $ch = curl_init($this->baseUrl . '/' . $scriptName);
        self::assertNotFalse($ch);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());
        $body = curl_exec($ch);
        self::assertIsString($body, $scriptName . ' returned no body');
        unset($ch);

        return $body;
    }
}
