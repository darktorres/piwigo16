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
 * Former install/db/132-database.php (P23 sub-batch 8g-2).
 */
final class Patch132 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '132';
    }

    #[\Override]
    public function description(): string
    {
        return 'Enlarge #users.password to increase security.';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        /** @var string $prefixeTable */
        global $prefixeTable;

        // we don't use USERS_TABLE because it might be an external table, here we
        // want to change to users table specific to Piwigo
        $query = 'ALTER TABLE ' . $prefixeTable . 'users CHANGE password password varchar(255) default NULL';
        $conn->executeStatement($query);

        echo "\n" . $this->description() . "\n";
    }
}
