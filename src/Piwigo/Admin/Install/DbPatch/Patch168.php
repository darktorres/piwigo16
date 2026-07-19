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
 * Former install/db/168-database.php (P23 sub-batch 8g-3).
 */
final class Patch168 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '168';
    }

    #[\Override]
    public function description(): string
    {
        return 'Create new column search_id in visits history table.';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $conn->executeStatement('ALTER TABLE `' . Tables::history() . '` ADD COLUMN `search_id` int(10) unsigned default NULL AFTER `category_id`;');

        echo "\n" . $this->description() . "\n";
    }
}
