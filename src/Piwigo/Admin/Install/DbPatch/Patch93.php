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
 * Former install/db/93-database.php (P23 sub-batch 8g-1). The description
 * doubles as the config row's comment column, as in the original.
 */
final class Patch93 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '93';
    }

    #[\Override]
    public function description(): string
    {
        return 'Monday may not be the first day of the week';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $query = '
INSERT INTO ' . Tables::config() . ' (param,value,comment)
  VALUES (\'week_starts_on\',\'monday\', \'' . $this->description() . '\')
;';
        $conn->executeStatement($query);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
