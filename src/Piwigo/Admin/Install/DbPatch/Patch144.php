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
 * Former install/db/144-database.php (P23 sub-batch 8g-3).
 */
final class Patch144 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '144';
    }

    #[\Override]
    public function description(): string
    {
        return 'add activation_key_expire';
    }

    #[\Override]
    public function apply(): void
    {
        // we use PREFIX_TABLE, in case Piwigo uses an external user table
        MysqliDb::query('
ALTER TABLE ' . Tables::userInfos() . '
  CHANGE activation_key activation_key VARCHAR(255) DEFAULT NULL,
  ADD COLUMN activation_key_expire DATETIME DEFAULT NULL AFTER activation_key
;');

        // purge current expiration keys
        MysqliDb::query('UPDATE ' . Tables::userInfos() . ' SET activation_key = NULL;');

        echo "\n" . $this->description() . "\n";
    }
}
