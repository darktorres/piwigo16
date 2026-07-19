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
 * Former install/db/172-database.php (P23 sub-batch 8g-3).
 */
final class Patch172 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '172';
    }

    #[\Override]
    public function description(): string
    {
        return 'reduce sessions.id length to 50 chars';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $query = 'ALTER TABLE `' . Tables::sessions() . '` CHANGE `id` `id` varchar(50) binary NOT NULL default \'\';';
        $conn->executeStatement($query);

        echo "\n" . $this->description() . "\n";
    }
}
