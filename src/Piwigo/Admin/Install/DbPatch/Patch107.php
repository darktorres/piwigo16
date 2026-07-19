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
 * Former install/db/107-database.php (P23 sub-batch 8g-2).
 */
final class Patch107 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '107';
    }

    #[\Override]
    public function description(): string
    {
        return 'Display new icons next albums and pictures';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $query = '
INSERT INTO ' . Tables::config() . ' (param,value,comment)
  VALUES (\'index_new_icon\',\'true\',\'Display new icons next albums and pictures\')
;';
        $conn->executeStatement($query);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
