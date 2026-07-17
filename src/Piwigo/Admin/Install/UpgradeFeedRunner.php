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

/**
 * upgrade_feed.php's orchestration, ported verbatim from that script's
 * former top-level code (P23 sub-batch 8f-6). The upgrade_feed.php entry
 * shell keeps only bootstrap (config/database/dblayer includes, the
 * check_upgrade_feed gate, the SEC-60-constrained define()s including the
 * former prepare_conf_upgrade() block) and then calls run().
 *
 * The frozen install/db/*-database.php scripts included by run() execute
 * inside this method's scope: the global declarations below are what keep
 * their bare top-level $conf/$prefixeTable references (e.g.
 * 125-database.php's `$conf_orig = $conf; ... $conf = $conf_orig;` swap)
 * pointing at the true globals, exactly as when this code ran at
 * upgrade_feed.php's own top level.
 */
class UpgradeFeedRunner
{
    public function run(): void
    {
        /**
         * @var array<string, mixed> $conf
         * @var string $prefixeTable
         */
        global $conf, $prefixeTable;

        // +-------------------------------------------------------------------+
        // |                         Database connection                        |
        // +-------------------------------------------------------------------+
        try {
            $db_host = $conf['db_host'];
            $db_user = $conf['db_user'];
            $db_password = $conf['db_password'];
            $db_base = $conf['db_base'];
            if (! is_string($db_host) || ! is_string($db_user) || ! is_string($db_password) || ! is_string($db_base)) {
                throw new Exception("Invalid database configuration: \$conf['db_host'], 'db_user', 'db_password' and 'db_base' must be strings.");
            }
            \Piwigo\Db\MysqliDb::connect(
                $db_host,
                $db_user,
                $db_password,
                $db_base
            );
        } catch (Exception $e) {
            // Historical quirk preserved verbatim: the `true` is passed to
            // l10n() as a (useless) sprintf argument, not to myError()'s
            // $die parameter -- which defaults to true anyway, so the die
            // behavior is the same as upgrade.php's connect error path.
            \Piwigo\Db\MysqliDb::myError(l10n($e->getMessage(), true));
        }

        \Piwigo\Db\MysqliDb::checkCharset();

        // +-------------------------------------------------------------------+
        // |                              Upgrades                              |
        // +-------------------------------------------------------------------+

        // retrieve already applied upgrades
        $query = '
SELECT id
  FROM ' . PREFIX_TABLE . 'upgrade
;';
        $applied = array_filter(\Piwigo\Db\MysqliDb::query2Array($query, null, 'id'), is_string(...));

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

            if (DbPatch\DbPatchRegistry::has($upgrade_id)) {
                // P23 sub-batch 8g: ported patches are real classes; the
                // ledger description comes from the class instead of the
                // former include-scope $upgrade_description variable.
                $patch = DbPatch\DbPatchRegistry::make($upgrade_id);
                $patch->apply();
                $upgrade_description = $patch->description();
            } else {
                // TRANSITION (8g-1..8g-5): not-yet-ported patches still
                // run as include'd install/db/*.php scripts. Each must set
                // $upgrade_description, briefly describing what it does.
                include UPGRADES_PATH . '/' . $upgrade_id . '-database.php';
            }

            // notify upgrade
            $query = '
INSERT INTO ' . PREFIX_TABLE . 'upgrade
  (id, applied, description)
  VALUES
  (\'' . $upgrade_id . '\', NOW(), \'' . $upgrade_description . '\')
;';
            \Piwigo\Db\MysqliDb::query($query);
        }

        echo '</pre>';
    }
}
