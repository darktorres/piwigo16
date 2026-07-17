<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Piwigo\Db\MysqliDb;
use Piwigo\Db\Tables;

/**
 * Former install/db/96-database.php (P23 sub-batch 8g-1).
 */
final class Patch96 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '96';
    }

    #[\Override]
    public function description(): string
    {
        return 'add time in images.date_creation column';
    }

    #[\Override]
    public function apply(): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        // Only MySQL is concerned, other DB engines are already "timestamp"
        if ($conf['dblayer'] == 'mysql') {
            $query = '
ALTER TABLE ' . Tables::images() . '
  MODIFY date_creation datetime
;';
            MysqliDb::query($query);
        }

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
