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
use Piwigo\Db\Tables;

/**
 * Former install/db/173-database.php (P23 sub-batch 8g-3).
 */
final class Patch173 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '173';
    }

    #[\Override]
    public function description(): string
    {
        return 'increase history.IP length from VARCHAR(16) to CHAR(39), IPv6 compatible';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $query = 'ALTER TABLE `' . Tables::history() . '` CHANGE `IP` `IP` char(39) NOT NULL default \'\';';
        $conn->executeStatement($query);

        echo "\n" . $this->description() . "\n";
    }
}
