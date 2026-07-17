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
 * Former install/db/116-database.php (P23 sub-batch 8g-2).
 */
final class Patch116 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '116';
    }

    #[\Override]
    public function description(): string
    {
        return 'Add #images.coi';
    }

    #[\Override]
    public function apply(): void
    {
        $query = '
ALTER TABLE ' . Tables::images() . ' ADD COLUMN coi CHAR(4) DEFAULT NULL COMMENT \'center of interest\' AFTER height
';
        MysqliDb::query($query);

        echo "\n"
        . '"' . $this->description() . '" ended'
        . "\n"
        ;
    }
}
