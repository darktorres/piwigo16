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
 * Former install/db/128-database.php (P23 sub-batch 8g-2).
 */
final class Patch128 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '128';
    }

    #[\Override]
    public function description(): string
    {
        return 'add anonymous_id in comments table';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $query = 'ALTER TABLE `' . Tables::comments() . '` ADD `anonymous_id` VARCHAR( 45 ) DEFAULT NULL;';
        $conn->executeStatement($query);

        echo "\n" . $this->description() . "\n";
    }
}
