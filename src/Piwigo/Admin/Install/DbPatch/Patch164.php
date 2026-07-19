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
 * Former install/db/164-database.php (P23 sub-batch 8g-3).
 */
final class Patch164 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '164';
    }

    #[\Override]
    public function description(): string
    {
        return 'Create dedicated user agent column for activity.';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $conn->executeStatement('
ALTER TABLE `' . Tables::activity() . '`
  ADD COLUMN `user_agent` varchar(255) default NULL
;');

        echo "\n" . $this->description() . "\n";
    }
}
