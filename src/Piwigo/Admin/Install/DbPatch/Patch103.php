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
 * Former install/db/103-database.php (P23 sub-batch 8g-2). The
 * description doubles as the config row's comment column, as in the
 * original.
 */
final class Patch103 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '103';
    }

    #[\Override]
    public function description(): string
    {
        return 'Extensions ignored for update';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $query = '
INSERT INTO ' . Tables::config() . ' (param,value,comment)
  VALUES (\'updates_ignored\',\'a:3:{s:7:"plugins";a:0:{}s:6:"themes";a:0:{}s:9:"languages";a:0:{}}\', \'' . $this->description() . '\')
;';
        $conn->executeStatement($query);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
