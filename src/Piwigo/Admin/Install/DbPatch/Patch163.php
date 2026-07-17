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
 * Former install/db/163-database.php (P23 sub-batch 8g-3).
 */
final class Patch163 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '163';
    }

    #[\Override]
    public function description(): string
    {
        return 'add user_infos.preferences';
    }

    #[\Override]
    public function apply(): void
    {
        MysqliDb::query('
ALTER TABLE `' . Tables::userInfos() . '`
  ADD COLUMN `preferences` TEXT default NULL
;');

        echo "\n" . $this->description() . "\n";
    }
}
