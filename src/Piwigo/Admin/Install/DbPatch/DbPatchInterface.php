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
 * apply() bodies are verbatim ports of the original scripts (raw
 * MysqliDb SQL preserved exactly, per the standing 8f-2 "no DBAL rewrite
 * in this phase" rule), including their own progress echoes and their
 * load-bearing `global $conf, $prefixeTable;` declarations.
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
