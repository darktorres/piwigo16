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
 * Former install/db/101-database.php (P23 sub-batch 8g-2). Historical
 * multi-dblayer branches kept verbatim (mysqli-only fork never takes the
 * pgsql/sqlite arms).
 */
final class Patch101 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '101';
    }

    #[\Override]
    public function description(): string
    {
        return 'merge nb_line_page and nb_image_line into nb_image_page';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $dblayer = LegacyDbLayer::value();

        // add column
        if ($dblayer === 'mysql') {
            $conn->executeStatement('
    ALTER TABLE ' . Tables::userInfos() . '
      ADD COLUMN `nb_image_page` smallint(3) unsigned NOT NULL default \'15\'
  ;');
        } elseif (in_array($dblayer, ['pgsql', 'sqlite', 'pdo-sqlite'])) {
            $conn->executeStatement('
    ALTER TABLE ' . Tables::userInfos() . '
      ADD COLUMN "nb_image_page" INTEGER default 15 NOT NULL
  ;');
        }

        // merge datas
        $conn->executeStatement('
  UPDATE ' . Tables::userInfos() . '
  SET nb_image_page = nb_line_page*nb_image_line
;');

        // delete old columns
        $conn->executeStatement('
  ALTER TABLE ' . Tables::userInfos() . '
    DROP `nb_line_page`,
    DROP `nb_image_line`
;');

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
