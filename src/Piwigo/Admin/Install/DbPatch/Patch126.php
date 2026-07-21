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
    public function apply(Connection $conn): void
    {
        $query = '
UPDATE ' . Tables::userInfos() . '
  SET language = :newLang
  WHERE language = :oldLang
;';
        $conn->executeStatement($query, [
            'newLang' => 'sl_SI',
            'oldLang' => 'sl_SL',
        ]);

        $query = '
UPDATE ' . Tables::languages() . '
  SET id = :newId
  WHERE id = :oldId
;';
        $conn->executeStatement($query, [
            'newId' => 'sl_SI',
            'oldId' => 'sl_SL',
        ]);

        echo "\n" . $this->description() . "\n";
    }
}
