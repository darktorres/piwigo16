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
 * Former install/db/139-database.php (P23 sub-batch 8g-2).
 */
final class Patch139 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '139';
    }

    #[\Override]
    public function description(): string
    {
        return 'add "latitude" and "longitude" fields';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        // add fields
        $query = '
ALTER TABLE ' . Tables::images() . '
  ADD `latitude` DOUBLE(8, 6) DEFAULT NULL,
  ADD `longitude` DOUBLE(9, 6) DEFAULT NULL
;';
        $conn->executeStatement($query);

        // add index
        $query = '
ALTER TABLE ' . Tables::images() . '
  ADD INDEX `images_i6` (`latitude`)
;';
        $conn->executeStatement($query);

        // search for old "lat" field
        $query = 'SHOW COLUMNS FROM ' . Tables::images() . ' LIKE "lat";';

        if ($conn->fetchAllAssociative($query) !== []) {
            // duplicate non-null values
            $query = '
UPDATE ' . Tables::images() . '
  SET latitude = lat,
    longitude = lon
  WHERE lat IS NOT NULL
    AND lon IS NOT NULL
;';
            $conn->executeStatement($query);
        }

        echo "\n" . $this->description() . "\n";
    }
}
