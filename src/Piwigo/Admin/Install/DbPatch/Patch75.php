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
use Piwigo\Config\Config;

/**
 * Former install/db/75-database.php (P23 sub-batch 8g-1). The original
 * overwrote $upgrade_description with the query text right before the
 * echo, so the ledger row and the progress output both carry the SQL --
 * description() reproduces that exact final value (the initial
 * 'Add blk_menubar config' copy-paste value was never observable).
 * ws_access has no Piwigo\Db\Tables::() accessor -- it's a table this
 * patch drops, not one the current schema still carries.
 */
final class Patch75 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '75';
    }

    #[\Override]
    public function description(): string
    {
        return 'DROP TABLE IF EXISTS ' . Config::dbPrefix() . 'ws_access';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $conn->executeStatement($this->description());

        echo "\n"
        . '"' . $this->description() . '" ended'
        . "\n"
        ;
    }
}
