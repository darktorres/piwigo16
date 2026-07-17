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
 * Former install/db/148-database.php (P23 sub-batch 8g-3).
 */
final class Patch148 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '148';
    }

    #[\Override]
    public function description(): string
    {
        return 'add auth_key_id in history table';
    }

    #[\Override]
    public function apply(): void
    {
        // we use PREFIX_TABLE, in case Piwigo uses an external user table
        MysqliDb::query('
ALTER TABLE `' . Tables::history() . '`
  ADD COLUMN `auth_key_id` int(11) unsigned DEFAULT NULL
;');

        echo "\n" . $this->description() . "\n";
    }
}
