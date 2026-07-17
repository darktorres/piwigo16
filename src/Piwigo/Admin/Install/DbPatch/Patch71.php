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
 * Former install/db/71-database.php (P23 sub-batch 8g-1).
 */
final class Patch71 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '71';
    }

    #[\Override]
    public function description(): string
    {
        return 'Delete unnecessary #history_summary.id, #history.year, #history.month, #history.day and #history.hour';
    }

    #[\Override]
    public function apply(): void
    {
        $query = 'ALTER TABLE ' . Tables::historySummary() . '
DROP PRIMARY KEY,
DROP COLUMN id,
ADD UNIQUE KEY history_summary_ymdh (`year`, `month`, `day`, `hour`)
;';
        MysqliDb::query($query);

        $query = 'ALTER TABLE ' . Tables::history() . '
DROP COLUMN year,
DROP COLUMN month,
DROP COLUMN day,
DROP COLUMN hour
;';
        MysqliDb::query($query);

        echo "\n"
        . '"' . $this->description() . '" ended'
        . "\n"
        ;
    }
}
