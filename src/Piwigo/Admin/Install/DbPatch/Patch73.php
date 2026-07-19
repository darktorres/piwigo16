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
 * Former install/db/73-database.php (P23 sub-batch 8g-1).
 */
final class Patch73 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '73';
    }

    #[\Override]
    public function description(): string
    {
        return 'Add #user_cache.cache_update_time';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $query = '
ALTER TABLE ' . Tables::userCache() . ' ADD COLUMN `cache_update_time` INTEGER UNSIGNED NOT NULL DEFAULT 0 AFTER need_update';
        $conn->executeStatement($query);

        echo "\n"
        . '"' . $this->description() . '" ended'
        . "\n"
        ;
    }
}
