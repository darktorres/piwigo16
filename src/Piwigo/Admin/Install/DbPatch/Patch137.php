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
 * Former install/db/137-database.php (P23 sub-batch 8g-2). The by-ref
 * foreach pair + unset() is verbatim original.
 */
final class Patch137 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '137';
    }

    #[\Override]
    public function description(): string
    {
        return 'add ASC keyword to categories image_order field';
    }

    #[\Override]
    public function apply(): void
    {
        $query = '
SELECT id, image_order
  FROM ' . Tables::categories() . '
  WHERE image_order != ""
;';
        $cats = MysqliDb::query2Array($query, 'id');

        foreach ($cats as $id => &$data) {
            $image_order = explode(',', $data['image_order']);
            foreach ($image_order as &$order) {
                if (strpos($order, ' ASC') === false && strpos($order, ' DESC') === false) {
                    $order .= ' ASC';
                }
            }
            unset($order);
            $data['image_order'] = implode(',', $image_order);
        }
        unset($data);

        MysqliDb::massUpdates(
            Tables::categories(),
            [
                'primary' => ['id'],
                'update' => ['image_order'],
            ],
            $cats
        );

        echo "\n" . $this->description() . "\n";
    }
}
