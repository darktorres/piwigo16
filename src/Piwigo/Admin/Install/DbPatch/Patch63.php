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
 * Former install/db/63-database.php (P23 sub-batch 8g-1).
 */
final class Patch63 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '63';
    }

    #[\Override]
    public function description(): string
    {
        return 'Add #user_infos.level, #images.level and #user_cache.forbidden_images';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $query = '
ALTER TABLE ' . Tables::images() . ' ADD COLUMN level TINYINT UNSIGNED NOT NULL DEFAULT 0
';
        $conn->executeStatement($query);

        $query = '
ALTER TABLE ' . Tables::userInfos() . ' ADD COLUMN level TINYINT UNSIGNED NOT NULL DEFAULT 0
';
        $conn->executeStatement($query);

        $query = '
ALTER TABLE ' . Tables::userCache() . ' ADD COLUMN image_access_type enum("NOT IN","IN") NOT NULL default "NOT IN"
';
        $conn->executeStatement($query);

        $query = '
ALTER TABLE ' . Tables::userCache() . ' ADD COLUMN image_access_list TEXT DEFAULT NULL
';
        $conn->executeStatement($query);

        $query = '
UPDATE ' . Tables::userInfos() . ' SET level = :level WHERE status = :status
';
        $conn->executeStatement($query, [
            'level' => 8,
            'status' => 'webmaster',
        ]);

        // user_cache.need_update is an ENUM('true','false') column (see
        // UserCacheRepository::markNeedUpdate()'s own real fix for the
        // same column) -- bind the actual string, not the bareword
        // numeric-coercion trick the original raw SQL relied on.
        $query = '
UPDATE ' . Tables::userCache() . ' SET need_update = :needUpdate
';
        $conn->executeStatement($query, [
            'needUpdate' => 'true',
        ]);

        echo "\n"
        . '"' . $this->description() . '" ended'
        . "\n"
        ;
    }
}
