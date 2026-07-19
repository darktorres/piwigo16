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
 * Former install/db/149-database.php (P23 sub-batch 8g-3).
 */
final class Patch149 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '149';
    }

    #[\Override]
    public function description(): string
    {
        return 'add last_visit+last_visit_from_history in user_infos table';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        // we use PREFIX_TABLE, in case Piwigo uses an external user table
        $conn->executeStatement('
ALTER TABLE `' . Tables::userInfos() . '`
  ADD COLUMN `last_visit` datetime default NULL,
  ADD COLUMN `last_visit_from_history` enum(\'true\',\'false\') NOT NULL default \'false\'
;');

        echo "\n" . $this->description() . "\n";
    }
}
