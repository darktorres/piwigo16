<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Piwigo\Db\MysqliDb;
use Piwigo\Db\Tables;

/**
 * Former install/db/61-database.php (P23 sub-batch 8g-1). The original's
 * include_once of include/constants.php (a file deleted phases ago,
 * leaving GROUPS_TABLE undefined -- one of the known broken-at-HEAD
 * frozen-script couplings) is replaced by Tables::groups().
 */
final class Patch61 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '61';
    }

    #[\Override]
    public function description(): string
    {
        return 'Add unique index on #_groups for name';
    }

    #[\Override]
    public function apply(): void
    {
        $query = '
alter table ' . Tables::groups() . '
  add UNIQUE KEY `groups_ui1` (`name`)
;';
        MysqliDb::query($query);

        echo "\n"
        . '"' . $this->description() . '" ended'
        . "\n"
        ;
    }
}
