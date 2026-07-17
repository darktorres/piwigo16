<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Piwigo\Cache\UserCacheInvalidator;
use Piwigo\Db\MysqliDb;
use Piwigo\Db\Tables;

/**
 * Former install/db/135-database.php (P23 sub-batch 8g-2).
 */
final class Patch135 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '135';
    }

    #[\Override]
    public function description(): string
    {
        return 'add nb available comments/tags';
    }

    #[\Override]
    public function apply(): void
    {
        $query = 'ALTER TABLE ' . Tables::userInfos() . '
ADD PRIMARY KEY (`user_id`)
, DROP INDEX `user_infos_ui1`';
        MysqliDb::query($query);

        $query = 'ALTER TABLE ' . Tables::userCache() . '
 ADD COLUMN `last_photo_date` datetime DEFAULT NULL AFTER `nb_total_images`';
        MysqliDb::query($query);
        UserCacheInvalidator::invalidate();

        $query = 'ALTER TABLE ' . Tables::userCache() . '
 ADD COLUMN `nb_available_tags` INT(5) DEFAULT NULL AFTER `last_photo_date`';
        MysqliDb::query($query);

        $query = 'ALTER TABLE ' . Tables::userCache() . '
 ADD COLUMN `nb_available_comments` INT(5) DEFAULT NULL AFTER `nb_available_tags`';
        MysqliDb::query($query);

        echo "\n" . $this->description() . "\n";
    }
}
