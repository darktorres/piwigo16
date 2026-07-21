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
 * Former install/db/111-database.php (P23 sub-batch 8g-2).
 */
final class Patch111 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '111';
    }

    #[\Override]
    public function description(): string
    {
        return 'New colum user_infos.activation_key';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $dblayer = LegacyDbLayer::value();

        // Add column
        $query = 'ALTER TABLE ' . Tables::userInfos() . ' ADD COLUMN ';

        if ($dblayer === 'mysql') {
            $query .= ' `activation_key` char(20) default NULL';
        }

        if (in_array($dblayer, ['pgsql', 'sqlite', 'pdo-sqlite'])) {
            $query .= ' "activation_key" CHAR(20) default NULL';
        }

        $query .= ';';

        $conn->executeStatement($query);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
