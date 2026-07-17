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
 * Former install/db/91-database.php (P23 sub-batch 8g-1).
 */
final class Patch91 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '91';
    }

    #[\Override]
    public function description(): string
    {
        return 'Remove adviser status.';
    }

    #[\Override]
    public function apply(): void
    {
        // Existing adviser become normal user
        $query = '
UPDATE ' . Tables::userInfos() . "
SET status = 'normal'
WHERE status IN ('webmaster', 'admin')
  AND adviser = 'true'
;";

        MysqliDb::query($query);

        // Remove adviser column
        $query = '
ALTER TABLE ' . Tables::userInfos() . '
DROP COLUMN adviser
;';

        MysqliDb::query($query);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
