<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install;

use Exception;
use Piwigo\Core\Lang;
use Piwigo\Core\Logger;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

/**
 * upgrade_feed.php's orchestration, ported verbatim from that script's
 * former top-level code (P23 sub-batch 8f-6). The upgrade_feed.php entry
 * shell keeps only bootstrap (config/database/dblayer includes, the
 * check_upgrade_feed gate) and then calls run().
 *
 * The frozen install/db/*-database.php scripts this class used to include
 * are gone (P23 sub-batch 8g-6 replaced them with real DbPatch classes) --
 * Legacy Coupling Retirement gap-closure (install/upgrade-flow constants
 * round): run() no longer reads a `PREFIX_TABLE` global either, it calls
 * Tables::upgrade() directly like the rest of this codebase, and its own
 * ledger INSERT is bound-parameterized instead of raw string-interpolated.
 */
final class UpgradeFeedRunner
{
    public function run(): void
    {
        // This request goes through InstallBootstrap::boot() (Legacy
        // Coupling Retirement Phase 8, 8b) but never CommonBootstrap::run(),
        // so CurrentUser is never guest-initialized either -- same latent
        // crash as InstallWizard::boot()'s own attachGlobals() call (see its
        // docblock). DbPatchRegistry::make(...)->apply() below dispatches
        // into frozen patch classes (Phase 1j) whose reach isn't fully
        // audited here; attachGlobals() is cheap, safe, and idempotent, so
        // it's applied uniformly across all 3 no-boot entry points rather
        // than only where a crash was actually reproduced.
        \Piwigo\Users\CurrentUser::attachGlobals();

        // Same reasoning, CurrentLogger this time -- same construction
        // recipe as RequestBootstrap::connect()'s own site. Direct
        // Config:: calls, so their declared `string` return types are
        // already certain, no is_string() re-guard needed.
        \Piwigo\Core\CurrentLogger::set(new Logger([
            'directory' => PHPWG_ROOT_PATH . \Piwigo\Config\Config::dataLocation() . \Piwigo\Config\Config::logDir(),
            'severity' => \Piwigo\Config\Config::logLevel(),
            'filename' => 'log_' . date('Y-m-d') . '_' . sha1(date('Y-m-d') . \Piwigo\Config\Config::dbPassword()) . '.txt',
            'globPattern' => 'log_*.txt',
            'archiveDays' => \Piwigo\Config\Config::logArchiveDays(),
        ]));

        // +-------------------------------------------------------------------+
        // |                         Database connection                        |
        // +-------------------------------------------------------------------+
        $conn = DbConnection::build();
        try {
            $conn->getNativeConnection();

            $version = new \Piwigo\Db\DbInfo($conn)
                ->version();
            if (version_compare($version, \Piwigo\Db\SqlDialect::REQUIRED_MYSQL_VERSION, '<')) {
                throw new Exception(sprintf('your MySQL version is too old, you have "%s" and you need at least "%s"', $version, \Piwigo\Db\SqlDialect::REQUIRED_MYSQL_VERSION));
            }
        } catch (Exception $e) {
            // fatalError() is declared `: never` -- PHPStan proves this
            // catch block never falls through, no fallback statement
            // needed after it.
            new \Piwigo\Html\HtmlService()
                ->fatalError(Lang::t($e->getMessage()));
        }

        // +-------------------------------------------------------------------+
        // |                              Upgrades                              |
        // +-------------------------------------------------------------------+

        // retrieve already applied upgrades
        $query = '
SELECT id
  FROM ' . Tables::upgrade() . '
;';
        $applied = array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            $conn->fetchFirstColumn($query)
        );

        // retrieve existing upgrades
        $existing = UpgradeService::getAvailableUpgradeIds();

        // which upgrades need to be applied?
        $to_apply = array_diff($existing, $applied);

        echo '<pre>';
        echo count($to_apply) . ' upgrades to apply';

        foreach ($to_apply as $upgrade_id) {
            $upgrade_description = '';

            echo "\n\n";
            echo '=== upgrade ' . $upgrade_id . "\n";

            // P23 sub-batch 8g: patches are real classes; the ledger
            // description comes from the class instead of the former
            // include-scope $upgrade_description variable.
            $patch = DbPatch\DbPatchRegistry::make($upgrade_id);
            $patch->apply($conn);
            $upgrade_description = $patch->description();

            // notify upgrade
            $query = '
INSERT INTO ' . Tables::upgrade() . '
  (id, applied, description)
  VALUES
  (:id, NOW(), :description)
;';
            $conn->executeStatement($query, [
                'id' => $upgrade_id,
                'description' => $upgrade_description,
            ]);
        }

        echo '</pre>';
    }
}
