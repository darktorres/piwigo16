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
 * Former install/db/127-database.php (P23 sub-batch 8g-2).
 */
final class Patch127 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '127';
    }

    #[\Override]
    public function description(): string
    {
        return 'rename language no_NO into nb_NO';
    }

    #[\Override]
    public function apply(): void
    {
        $query = '
UPDATE ' . Tables::userInfos() . '
  SET language = \'nb_NO\'
  WHERE language = \'no_NO\'
;';
        MysqliDb::query($query);

        $query = '
UPDATE ' . Tables::languages() . '
  SET id = \'nb_NO\'
  WHERE id = \'no_NO\'
;';
        MysqliDb::query($query);

        echo "\n" . $this->description() . "\n";
    }
}
