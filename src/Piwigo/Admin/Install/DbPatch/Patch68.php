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
 * Former install/db/68-database.php (P23 sub-batch 8g-1).
 */
final class Patch68 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '68';
    }

    #[\Override]
    public function description(): string
    {
        return 'Change type from text to mediumtext for #sessions.data #user_cache.forbidden_categories and #user_cache.image_access_list';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $query = '
ALTER TABLE ' . Tables::sessions() . '
  MODIFY COLUMN data MEDIUMTEXT NOT NULL';
        $conn->executeStatement($query);

        $query = '
ALTER TABLE ' . Tables::userCache() . '
  MODIFY COLUMN forbidden_categories MEDIUMTEXT,
  MODIFY COLUMN image_access_list MEDIUMTEXT
  ';
        $conn->executeStatement($query);

        echo "\n"
        . '"' . $this->description() . '" ended'
        . "\n"
        ;
    }
}
