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
 * Former install/db/77-database.php (P23 sub-batch 8g-1).
 */
final class Patch77 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '77';
    }

    #[\Override]
    public function description(): string
    {
        return 'images.file categories.permalink old_permalinks.permalink - become binary';
    }

    #[\Override]
    public function apply(): void
    {
        $query = 'ALTER TABLE ' . Tables::categories() . '
  MODIFY COLUMN permalink varchar(64) binary default NULL';
        MysqliDb::query($query);

        $query = 'ALTER TABLE ' . Tables::oldPermalinks() . '
  MODIFY COLUMN permalink varchar(64) binary NOT NULL default ""';
        MysqliDb::query($query);

        $query = 'ALTER TABLE ' . Tables::images() . '
  MODIFY COLUMN file varchar(255) binary NOT NULL default ""';
        MysqliDb::query($query);

        echo "\n"
        . '"' . $this->description() . '" ended'
        . "\n"
        ;
    }
}
