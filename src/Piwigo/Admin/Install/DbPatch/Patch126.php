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
 * Former install/db/126-database.php (P23 sub-batch 8g-2).
 */
final class Patch126 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '126';
    }

    #[\Override]
    public function description(): string
    {
        return 'rename language sl_SL into sl_SI';
    }

    #[\Override]
    public function apply(): void
    {
        $query = '
UPDATE ' . Tables::userInfos() . '
  SET language = \'sl_SI\'
  WHERE language = \'sl_SL\'
;';
        MysqliDb::query($query);

        $query = '
UPDATE ' . Tables::languages() . '
  SET id = \'sl_SI\'
  WHERE id = \'sl_SL\'
;';
        MysqliDb::query($query);

        echo "\n" . $this->description() . "\n";
    }
}
