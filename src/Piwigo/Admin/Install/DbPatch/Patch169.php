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
 * Former install/db/169-database.php (P23 sub-batch 8g-3).
 */
final class Patch169 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '169';
    }

    #[\Override]
    public function description(): string
    {
        return 'Delete old column search.last_seen, never really used.';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $conn->executeStatement('UPDATE `' . Tables::search() . '` SET `created_on` = `last_seen` where `created_on` IS NULL AND `last_seen` IS NOT NULL;');
        $conn->executeStatement('ALTER TABLE `' . Tables::search() . '` DROP COLUMN `last_seen`;');

        echo "\n" . $this->description() . "\n";
    }
}
