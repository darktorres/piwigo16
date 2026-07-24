<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\VersionUpgrade;

use Doctrine\DBAL\Connection;

/**
 * One version-step upgrade (P23 sub-batch 8g-4/8g-5) -- the OOP port of a
 * former install/upgrade_X.Y.Z.php script. UpgradeRunner::performUpgrade()
 * detects the gallery's release via its column/ledger ladder and applies
 * the matching step; each step's apply() ends by CHAINING to the next
 * step's class, exactly as the original scripts chain-include_once'd each
 * other (the chain is linear, so each step still runs at most once per
 * request).
 *
 * The originals' top-of-file `PHPWG_IN_UPGRADE or die('Hacking attempt!')`
 * guards blocked direct HTTP loads of the script files; class files under
 * src/Piwigo carry no top-level side effects, so the guard's job is moot
 * -- the runner (behind checkUpgradeAccessRights()) is the only caller.
 * apply() bodies are otherwise verbatim ports: database.inc.php snippets
 * pushed through
 * {@see \Piwigo\Admin\Install\DbPatch\DatabaseConfigChanges} instead of
 * the former runner-scope-local $mysql_changes array. The former
 * `global` declarations carrying the include-scope contract ($conf/
 * $prefixeTable/$page/$template/$persistent_cache/$last_time) are gone --
 * each site reads Tables::/DbCredentials::current()->prefix directly, or, for the
 * handful of keys genuinely only ever set by a site's own
 * local/config/config.inc.php (never mirrored into CurrentConfig::),
 * {@see \Piwigo\Admin\Install\DbPatch\LegacyFileConf::read()}/
 * {@see \Piwigo\Admin\Install\DbPatch\LegacyDbLayer::value()} (Legacy
 * Coupling Retirement gap-closure, "fix all" pass).
 *
 * DML values are no longer raw-interpolated SQL text either: Legacy
 * Coupling Retirement Workstream B (gap-closure round 2) converted every
 * INSERT/UPDATE/DELETE/REPLACE's hardcoded or computed value onto a
 * bound parameter via `Connection::executeStatement()`'s/`fetch*()`'s
 * own `$params` argument, or {@see \Piwigo\Db\BatchWriter::singleInsert()}/
 * {@see \Piwigo\Db\BatchWriter::massInsert()} for plain config-table
 * inserts. DDL and identifier concatenation (table/column names) are
 * unchanged raw SQL text. See
 * `docs/plan/legacy-coupling-retirement.md`'s "Gap-closure: repo-wide
 * legacy sweep round 2" section for the full per-file rationale,
 * including the two real double-escaping bugs (stray `addslashes()`
 * calls) it fixed along the way.
 */
interface VersionUpgradeInterface
{
    /**
     * The release this step upgrades FROM (historically the X.Y.Z in the
     * script's filename), e.g. '1.3.0'.
     */
    public function versionFrom(): string;

    /**
     * Executes the step, echoing its own progress output, then chains to
     * the next step (if any).
     */
    public function apply(Connection $conn): void;
}
