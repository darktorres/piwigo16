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
 * Former install/db/133-database.php (P23 sub-batch 8g-2).
 */
final class Patch133 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '133';
    }

    #[\Override]
    public function description(): string
    {
        return '#categories.site_id default value.';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $query = 'ALTER TABLE ' . Tables::categories() . ' CHANGE site_id site_id tinyint(4) unsigned default NULL';
        $conn->executeStatement($query);

        $query = 'UPDATE ' . Tables::categories() . ' SET site_id=NULL WHERE dir IS NULL';
        $conn->executeStatement($query);

        echo "\n" . $this->description() . "\n";
    }
}
