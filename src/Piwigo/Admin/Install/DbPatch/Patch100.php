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
 * Former install/db/100-database.php (P23 sub-batch 8g-1). Historical
 * multi-dblayer branches kept verbatim (mysqli-only fork never takes the
 * pgsql/sqlite arms).
 */
final class Patch100 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '100';
    }

    #[\Override]
    public function description(): string
    {
        return 'add high_width and high_height fields into IMAGES_TABLE';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        if ($conf['dblayer'] == 'mysql') {
            $query = 'ALTER TABLE ' . Tables::images() . '
    ADD COLUMN `high_width` smallint(9) unsigned default NULL,
    ADD COLUMN `high_height` smallint(9) unsigned default NULL;';
        }

        if (in_array($conf['dblayer'], ['pgsql', 'sqlite', 'pdo-sqlite'])) {
            $query = 'ALTER TABLE ' . Tables::images() . '
    ADD COLUMN "high_width" INTEGER,
    ADD COLUMN "high_height" INTEGER;';
        }

        $conn->executeStatement($query);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
