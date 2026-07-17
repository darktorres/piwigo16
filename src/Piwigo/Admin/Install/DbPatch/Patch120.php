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
 * Former install/db/120-database.php (P23 sub-batch 8g-2).
 */
final class Patch120 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '120';
    }

    #[\Override]
    public function description(): string
    {
        return 'rotation mode (code, not angle) is stored in the database';
    }

    #[\Override]
    public function apply(): void
    {
        $query = 'ALTER TABLE ' . Tables::images() . ' ADD COLUMN rotation tinyint unsigned DEFAULT NULL';
        MysqliDb::query($query);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
