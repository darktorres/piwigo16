<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\VersionUpgrade;

use Doctrine\DBAL\Connection;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\Tables;

/**
 * Former install/upgrade_1.3.0.php (P23 sub-batch 8g-4): upgrade from
 * 1.3.0 to 1.3.1, then chain to UpgradeFrom_1_3_1. The hardcoded
 * phpwebgallery_ prefix + str_replace(PREFIX_TABLE) dance is verbatim
 * original (those literal names are what a 1.3.0-era dump contains).
 */
final class UpgradeFrom_1_3_0 implements VersionUpgradeInterface
{
    #[\Override]
    public function versionFrom(): string
    {
        return '1.3.0';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        /** @var string $prefixeTable */
        global $prefixeTable;

        $queries = [
            "
ALTER TABLE phpwebgallery_categories
  ADD COLUMN uppercats varchar(255) NOT NULL default ''
;",

            "
CREATE TABLE phpwebgallery_user_category (
  user_id smallint(5) unsigned NOT NULL default '0'
)
;",

            '
ALTER TABLE phpwebgallery_categories
  ADD INDEX id (id)
;',

            '
ALTER TABLE phpwebgallery_categories
  ADD INDEX id_uppercat (id_uppercat)
;',

            '
ALTER TABLE phpwebgallery_image_category
  ADD INDEX category_id (category_id)
;',

            '
ALTER TABLE phpwebgallery_image_category
  ADD INDEX image_id (image_id)
;',
        ];

        foreach ($queries as $query) {
            $query = str_replace('phpwebgallery_', $prefixeTable, $query);
            $conn->executeStatement($query);
        }
        // filling the new column categories.uppercats
        $id_uppercats = [];

        $query = '
SELECT id, id_uppercat
  FROM ' . Tables::categories() . '
;';
        foreach ($conn->fetchAllAssociative($query) as $row) {
            $id_uppercat = is_scalar($row['id_uppercat']) ? (string) $row['id_uppercat'] : '';
            if ($id_uppercat === '') {
                $id_uppercat = 'NULL';
            }
            $id = is_scalar($row['id']) ? (string) $row['id'] : '';
            $id_uppercats[$id] = $id_uppercat;
        }

        $datas = [];

        foreach (array_keys($id_uppercats) as $id) {
            $data = [];
            $data['id'] = $id;
            $uppercats = [];

            array_push($uppercats, $id);
            while (isset($id_uppercats[$id]) and $id_uppercats[$id] !== 'NULL') {
                array_push($uppercats, $id_uppercats[$id]);
                $id = $id_uppercats[$id];
            }
            $data['uppercats'] = implode(',', array_reverse($uppercats));

            array_push($datas, $data);
        }

        new BatchWriter($conn)
            ->massUpdate(
                Tables::categories(),
                [
                    'primary' => ['id'],
                    'update' => ['uppercats'],
                ],
                $datas
            );

        // now we upgrade from 1.3.1 to 1.6.0
        new UpgradeFrom_1_3_1()
            ->apply($conn);
    }
}
