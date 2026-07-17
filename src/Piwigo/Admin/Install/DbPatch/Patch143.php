<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Piwigo\Db\MysqliDb;
use Piwigo\Db\Tables;

/**
 * Former install/db/143-database.php (P23 sub-batch 8g-3). The users
 * table keeps its $prefixeTable construction (the original deliberately
 * avoided the USERS_TABLE constant, which may point at an external user
 * table).
 */
final class Patch143 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '143';
    }

    #[\Override]
    public function description(): string
    {
        return 'enlarge your user_id (16 millions possible users)';
    }

    #[\Override]
    public function apply(): void
    {
        /** @var string $prefixeTable */
        global $prefixeTable;

        // we use PREFIX_TABLE, in case Piwigo uses an external user table
        MysqliDb::query('ALTER TABLE ' . $prefixeTable . 'users CHANGE id id MEDIUMINT UNSIGNED NOT NULL AUTO_INCREMENT;');
        MysqliDb::query('ALTER TABLE ' . Tables::images() . ' CHANGE added_by added_by MEDIUMINT UNSIGNED NOT NULL DEFAULT \'0\';');
        MysqliDb::query('ALTER TABLE ' . Tables::comments() . ' CHANGE author_id author_id MEDIUMINT UNSIGNED DEFAULT NULL;');

        $tables = [
            Tables::userAccess(),
            Tables::userCache(),
            Tables::userFeed(),
            Tables::userGroup(),
            Tables::userInfos(),
            Tables::userCacheCategories(),
            Tables::userMailNotification(),
            Tables::rate(),
            Tables::caddie(),
            Tables::favorites(),
            Tables::history(),
        ];

        foreach ($tables as $table) {
            MysqliDb::query('
ALTER TABLE ' . $table . '
  CHANGE user_id user_id MEDIUMINT UNSIGNED NOT NULL DEFAULT \'0\'
;');
        }

        echo "\n" . $this->description() . "\n";
    }
}
