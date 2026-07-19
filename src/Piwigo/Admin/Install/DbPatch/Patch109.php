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
 * Former install/db/109-database.php (P23 sub-batch 8g-2). The original
 * had NO progress echo at all (its description only reached the ledger
 * row) -- preserved.
 */
final class Patch109 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '109';
    }

    #[\Override]
    public function description(): string
    {
        return 'Rename #images.average_rate to ratingscore.';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        if ($conf['dblayer'] == 'mysql') {
            $q = 'ALTER TABLE ' . Tables::images() . ' CHANGE average_rate rating_score float(5,2) unsigned default NULL';
        } else {
            $q = 'ALTER TABLE ' . Tables::images() . ' RENAME average_rate TO rating_score';
        }
        $conn->executeStatement($q);

        $q = 'UPDATE ' . Tables::categories() . " SET image_order=REPLACE(image_order, 'average_rate', 'rating_score')";
        $conn->executeStatement($q);

        $q = 'UPDATE ' . Tables::config() . " SET value=REPLACE(value, 'average_rate', 'rating_score')
WHERE param IN ('picture_informations', 'order_by', 'order_by_inside_category')";
        $conn->executeStatement($q);
    }
}
