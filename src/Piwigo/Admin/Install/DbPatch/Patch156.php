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
 * Former install/db/156-database.php (P23 sub-batch 8g-3).
 */
final class Patch156 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '156';
    }

    #[\Override]
    public function description(): string
    {
        return 'bug fixing, change column type for activity.occured_on';
    }

    #[\Override]
    public function apply(): void
    {
        $row = MysqliDb::fetchAssoc(MysqliDb::query('SHOW COLUMNS FROM `' . Tables::activity() . '` LIKE "occured_on";'));
        if (! preg_match('/^TIMESTAMP/i', $row['Type'])) {
            $query = 'ALTER TABLE `' . Tables::activity() . '` CHANGE `occured_on` `occured_on` TIMESTAMP DEFAULT CURRENT_TIMESTAMP;';
            MysqliDb::query($query);
        }

        echo "\n" . $this->description() . "\n";
    }
}
