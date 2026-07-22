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
        $dblayer = LegacyDbLayer::value();

        if (in_array($dblayer, ['mysql', 'mysqli'], true)) {
            $query = 'ALTER TABLE ' . Tables::images() . '
    ADD COLUMN `high_width` smallint(9) unsigned default NULL,
    ADD COLUMN `high_height` smallint(9) unsigned default NULL;';
        }

        if (in_array($dblayer, ['pgsql', 'sqlite', 'pdo-sqlite'])) {
            $query = 'ALTER TABLE ' . Tables::images() . '
    ADD COLUMN "high_width" INTEGER,
    ADD COLUMN "high_height" INTEGER;';
        }

        // LegacyDbLayer::value() only ever produces one of the 5 values
        // checked above (mysql/mysqli/pgsql/sqlite/pdo-sqlite) anywhere in
        // this codebase, so $query is always set in practice; PHPStan can't
        // prove that from value()'s unbounded `string` return type.
        // @phpstan-ignore variable.undefined
        $conn->executeStatement($query);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
