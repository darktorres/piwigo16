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
 * Former install/db/82-database.php (P23 sub-batch 8g-1). Bare-description
 * echo (no '"..." ended' wrapper), preserved.
 */
final class Patch82 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '82';
    }

    #[\Override]
    public function description(): string
    {
        return 'add new column to save author_id.
Guest users names are saved in author column';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $query = '
ALTER TABLE ' . Tables::comments() . '
  ADD COLUMN author_id smallint(5) DEFAULT NULL
;';
        $conn->executeStatement($query);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
