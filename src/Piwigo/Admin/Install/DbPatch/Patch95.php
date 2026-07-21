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
 * Former install/db/95-database.php (P23 sub-batch 8g-1). Historical
 * multi-dblayer branches kept verbatim (mysqli-only fork never takes the
 * pgsql/sqlite arms).
 */
final class Patch95 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '95';
    }

    #[\Override]
    public function description(): string
    {
        return 'New colum user_cache_categories.user_representative_picture_id';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $dblayer = LegacyDbLayer::value();

        // Add column
        $query = 'ALTER TABLE ' . Tables::userCacheCategories() . ' ADD COLUMN ';

        if ($dblayer === 'mysql') {
            $query .= ' `user_representative_picture_id` mediumint(8) unsigned default NULL';
        }

        if (in_array($dblayer, ['pgsql', 'sqlite', 'pdo-sqlite'])) {
            $query .= ' "user_representative_picture_id" INTEGER';
        }

        $query .= ';';

        $conn->executeStatement($query);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
