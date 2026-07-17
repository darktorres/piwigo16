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
 * Former install/db/98-database.php (P23 sub-batch 8g-1).
 */
final class Patch98 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '98';
    }

    #[\Override]
    public function description(): string
    {
        return 'add the config parameter comments_update_validation';
    }

    #[\Override]
    public function apply(): void
    {
        $query = '
INSERT INTO ' . Tables::config() . '
  (
    param,
    value,
    comment
  )
  VALUES (
    \'comments_update_validation\',
    false,
    \'administrators validate users updated comments before becoming visible\'
   )
;';

        MysqliDb::query($query);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
