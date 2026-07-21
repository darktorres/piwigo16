<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Doctrine\DBAL\Connection;

/**
 * One numbered one-shot database patch (P23 sub-batch 8g). Each class is
 * the OOP port of a former install/db/<id>-database.php script, applied
 * exactly once per gallery and recorded by id in the `upgrade` ledger
 * table -- {@see \Piwigo\Admin\Install\UpgradeFeedRunner::run()} computes
 * (available - applied) and runs the difference in natcasesort order via
 * {@see DbPatchRegistry}.
 *
 * apply() bodies are otherwise verbatim ports of the original scripts,
 * including their own progress echoes. The former
 * `global $conf, $prefixeTable;` declarations are gone -- each site reads
 * Tables::/Config::dbPrefix() directly, or, for the handful of keys
 * genuinely only ever set by a site's own local/config/config.inc.php
 * (never mirrored into Config::), LegacyFileConf::read()/LegacyDbLayer::
 * value() (Legacy Coupling Retirement gap-closure, "fix all" pass).
 *
 * The earlier "no DBAL rewrite in this phase" rule (8f-2) has since been
 * superseded: Legacy Coupling Retirement Workstream B (gap-closure round
 * 2) converted every DML statement's interpolated/hardcoded values onto
 * bound parameters via `Connection::executeStatement()`'s/`fetch*()`'s
 * own `$params` argument, or `Piwigo\Db\BatchWriter::singleInsert()`/
 * `massInsert()` for plain config-table inserts. DDL (CREATE/ALTER/DROP
 * TABLE) and identifier concatenation (table/column names, which SQL
 * can't parameterize) are unchanged raw SQL text -- only genuine DML
 * *values* were touched. See `docs/plan/legacy-coupling-retirement.md`'s
 * "Gap-closure: repo-wide legacy sweep round 2" section for the full
 * per-batch rationale.
 */
interface DbPatchInterface
{
    /**
     * The ledger id (historically the numeric filename prefix), e.g. '61'.
     */
    public function id(): string;

    /**
     * The one-line human description recorded in the ledger row
     * (historically the script's $upgrade_description variable).
     */
    public function description(): string;

    /**
     * Executes the patch. Echoes its own progress output, exactly as the
     * original script did when include'd by upgrade_feed.php.
     */
    public function apply(Connection $conn): void;
}
