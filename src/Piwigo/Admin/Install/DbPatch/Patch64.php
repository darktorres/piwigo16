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
 * Former install/db/64-database.php (P23 sub-batch 8g-1).
 */
final class Patch64 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '64';
    }

    #[\Override]
    public function description(): string
    {
        return 'Activation of c13_upgrade plugin';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $query = '
REPLACE INTO ' . Tables::plugins() . '
  (id, state)
  VALUES (\'c13y_upgrade\', \'active\')
;';
        $conn->executeStatement($query);

        echo "\n"
        . '"' . $this->description() . '" ended'
        . "\n"
        ;
    }
}
