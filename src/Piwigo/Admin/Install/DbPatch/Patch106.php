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
 * Former install/db/106-database.php (P23 sub-batch 8g-2). Reads the
 * file-config $conf['order_by']/$conf['order_by_inside_category'] values
 * from the true global to seed the DB rows, as the original did.
 */
final class Patch106 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '106';
    }

    #[\Override]
    public function description(): string
    {
        return 'add order parameters to bdd';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $query = '
INSERT INTO ' . Tables::config() . '(param,value,comment)
  VALUES (\'order_by\', \'' . $conf['order_by'] . '\', \'default photos order\')
;';
        $conn->executeStatement($query);

        $query = '
INSERT INTO ' . Tables::config() . '(param,value,comment)
  VALUES (\'order_by_inside_category\', \'' . $conf['order_by_inside_category'] . '\', \'default photos order inside category\')
;';
        $conn->executeStatement($query);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
