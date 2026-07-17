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
 * Former install/db/155-database.php (P23 sub-batch 8g-3).
 */
final class Patch155 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '155';
    }

    #[\Override]
    public function description(): string
    {
        return 'add columns session_idx+ip_address in activity table';
    }

    #[\Override]
    public function apply(): void
    {
        MysqliDb::query('alter table `' . Tables::activity() . '` add column `session_idx` varchar(255) NOT NULL after `performed_by`;');
        MysqliDb::query('alter table `' . Tables::activity() . '` add column `ip_address` varchar(50) default null after session_idx;');

        echo "\n" . $this->description() . "\n";
    }
}
