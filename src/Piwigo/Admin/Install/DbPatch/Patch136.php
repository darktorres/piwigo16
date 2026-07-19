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
use Piwigo\Cache\UserCacheInvalidator;
use Piwigo\Db\Tables;

/**
 * Former install/db/136-database.php (P23 sub-batch 8g-2).
 */
final class Patch136 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '136';
    }

    #[\Override]
    public function description(): string
    {
        return 'add nb direct child categories';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $query = '
ALTER TABLE ' . Tables::userCacheCategories() . '
  ADD COLUMN nb_categories mediumint(8) unsigned NOT NULL default 0 AFTER count_images';
        $conn->executeStatement($query);

        UserCacheInvalidator::invalidate();

        echo "\n" . $this->description() . "\n";
    }
}
