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
 * Former install/db/79-database.php (P23 sub-batch 8g-1).
 */
final class Patch79 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '79';
    }

    #[\Override]
    public function description(): string
    {
        return 'Update default template';
    }

    #[\Override]
    public function apply(): void
    {
        // set yoga/Sylvia as default value for user_infos.template column
        $query = '
ALTER TABLE ' . Tables::userInfos() . '
  CHANGE COLUMN template template varchar(255) NOT NULL default \'yoga/Sylvia\'
;';
        MysqliDb::query($query);

        echo "\n"
        . 'Default template modified to yoga/Sylvia'
        . "\n"
        ;
    }
}
