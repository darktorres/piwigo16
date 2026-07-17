<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Piwigo\Core\StringHelper;
use Piwigo\Db\MysqliDb;
use Piwigo\Db\Tables;

/**
 * Former install/db/117-database.php (P23 sub-batch 8g-2).
 */
final class Patch117 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '117';
    }

    #[\Override]
    public function description(): string
    {
        return 'fill empty images name with filename';
    }

    #[\Override]
    public function apply(): void
    {
        $query = 'SELECT id, file FROM ' . Tables::images() . ' WHERE name IS NULL;';
        $images = MysqliDb::query($query);

        $updates = [];
        while ($row = MysqliDb::fetchAssoc($images)) {
            $updates[] = [
                'id' => $row['id'],
                'name' => StringHelper::getNameFromFile($row['file']),
            ];
        }

        MysqliDb::massUpdates(
            Tables::images(),
            [
                'primary' => ['id'],
                'update' => ['name'],
            ],
            $updates
        );

        echo "\n"
        . '"' . $this->description() . '" ended'
        . "\n"
        ;
    }
}
