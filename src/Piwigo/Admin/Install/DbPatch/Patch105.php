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
 * Former install/db/105-database.php (P23 sub-batch 8g-2).
 */
final class Patch105 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '105';
    }

    #[\Override]
    public function description(): string
    {
        return 'Show menubar on picture page';
    }

    #[\Override]
    public function apply(): void
    {
        $query = '
INSERT INTO ' . Tables::config() . ' (param,value,comment)
  VALUES (\'picture_menu\',\'false\', \'' . $this->description() . '\')
;';
        MysqliDb::query($query);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
