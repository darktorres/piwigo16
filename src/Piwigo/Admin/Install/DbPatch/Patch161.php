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
 * Former install/db/161-database.php (P23 sub-batch 8g-3).
 */
final class Patch161 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '161';
    }

    #[\Override]
    public function description(): string
    {
        return 'remove doubled activities on tag addition';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $tag_ids_added = [];
        $to_delete_activities = [];

        $query = '
SELECT
    *
  FROM ' . Tables::activity() . '
  WHERE object = "tag"
    AND action = "add"
  ORDER BY activity_id ASC
;';

        foreach ($conn->fetchAllAssociative($query) as $row) {
            $object_id = is_int($row['object_id']) || is_string($row['object_id']) ? $row['object_id'] : '';
            if (isset($tag_ids_added[$object_id])) {
                array_push($to_delete_activities, is_scalar($row['activity_id']) ? (string) $row['activity_id'] : '');
            } else {
                $tag_ids_added[$object_id] = 1;
            }
        }

        if (count($to_delete_activities) > 0) {
            $query = '
DELETE
  FROM ' . Tables::activity() . '
  WHERE activity_id IN (' . implode(',', $to_delete_activities) . ')
;';
            $conn->executeStatement($query);
        }

        echo "\n" . $this->description() . "\n";
    }
}
