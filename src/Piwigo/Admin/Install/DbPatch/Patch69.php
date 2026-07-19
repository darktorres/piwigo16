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
 * Former install/db/69-database.php (P23 sub-batch 8g-1). The bare
 * invalidate_user_cache() call became UserCacheInvalidator::invalidate()
 * (same target the frozen-script delegate forwarded to).
 */
final class Patch69 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '69';
    }

    #[\Override]
    public function description(): string
    {
        return 'Move #categories.date_last and nb_images to #user_cache_categories';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $query = '
ALTER TABLE ' . Tables::userCacheCategories() . '
  ADD COLUMN date_last datetime default NULL AFTER cat_id,
  ADD COLUMN nb_images mediumint(8) unsigned NOT NULL default 0 AFTER max_date_last';
        $conn->executeStatement($query);

        $query = '
ALTER TABLE ' . Tables::categories() . '
  DROP COLUMN date_last,
  DROP COLUMN nb_images
  ';
        $conn->executeStatement($query);

        UserCacheInvalidator::invalidate(); // just to force recalculation

        echo "\n"
        . '"' . $this->description() . '" ended'
        . "\n"
        ;
    }
}
