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
 * Former install/db/141-database.php (P23 sub-batch 8g-3).
 */
final class Patch141 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '141';
    }

    #[\Override]
    public function description(): string
    {
        return 'add lastmodified field for categories, images, groups, users, tags';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $tables = [
            Tables::categories(),
            Tables::groups(),
            Tables::images(),
            Tables::tags(),
            Tables::userInfos(),
        ];

        foreach ($tables as $table) {
            $conn->executeStatement('
ALTER TABLE ' . $table . '
  ADD `lastmodified` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  ADD INDEX `lastmodified` (`lastmodified`)
;');
        }

        echo "\n" . $this->description() . "\n";
    }
}
