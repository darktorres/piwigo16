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
use Piwigo\Db\BatchWriter;
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
    public function apply(Connection $conn): void
    {
        $query = '
SELECT id, image_order
  FROM ' . Tables::categories() . '
  WHERE image_order != ""
;';
        $cats = array_column($conn->fetchAllAssociative($query), null, 'id');

        foreach ($cats as $id => &$data) {
            $image_order_raw = is_scalar($data['image_order']) ? (string) $data['image_order'] : '';
            $image_order = explode(',', $image_order_raw);
            foreach ($image_order as &$order) {
                if (! str_contains($order, ' ASC') && ! str_contains($order, ' DESC')) {
                    $order .= ' ASC';
                }
            }
            unset($order);
            $data['image_order'] = implode(',', $image_order);
        }
        unset($data);

        new BatchWriter($conn)
            ->massUpdate(
                Tables::categories(),
                [
                    'primary' => ['id'],
                    'update' => ['image_order'],
                ],
                array_values($cats)
            );

        echo "\n" . $this->description() . "\n";
    }
}
