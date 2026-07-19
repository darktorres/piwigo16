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
 * Former install/db/140-database.php (P23 sub-batch 8g-2).
 */
final class Patch140 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '140';
    }

    #[\Override]
    public function description(): string
    {
        return '#tags.name is not binary';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        // add fields
        $query = 'ALTER TABLE ' . Tables::tags() . ' CHANGE COLUMN `name` `name` VARCHAR(255) NOT NULL DEFAULT \'\'';
        $conn->executeStatement($query);

        echo "\n" . $this->description() . "\n";
    }
}
