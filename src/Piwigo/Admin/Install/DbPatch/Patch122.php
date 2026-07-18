<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Piwigo\Core\TimingHelper;
use Piwigo\Db\MysqliDb;
use Piwigo\Db\Tables;

/**
 * Former install/db/122-database.php (P23 sub-batch 8g-2).
 */
final class Patch122 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '122';
    }

    #[\Override]
    public function description(): string
    {
        return 'derivatives: new organization of "upload" and "galleries" directories';
    }

    #[\Override]
    public function apply(): void
    {
        $query = '
SELECT
    id,
    path,
    tn_ext,
    has_high,
    high_filesize,
    high_width,
    high_height
  FROM ' . Tables::images() . '
;';
        $result = MysqliDb::query($query);
        $starttime = TimingHelper::getMoment();

        $updates = [];

        while ($row = MysqliDb::fetchAssoc($result)) {
            if ($row['has_high'] == 'true') {
                $high_path = dirname((string) $row['path']) . '/pwg_high/' . basename((string) $row['path']);
                rename($high_path, $row['path']);

                array_push(
                    $updates,
                    [
                        'id' => $row['id'],
                        'width' => $row['high_width'],
                        'height' => $row['high_height'],
                        'filesize' => $row['high_filesize'],
                    ]
                );
            }
        }

        if (count($updates) > 0) {
            MysqliDb::massUpdates(
                Tables::images(),
                [
                    'primary' => ['id'],
                    'update' => ['width', 'height', 'filesize'],
                ],
                $updates
            );
        }

        echo "\n"
        . $this->description() . sprintf(' (execution in %.3fs)', (TimingHelper::getMoment() - $starttime))
        . "\n"
        ;
    }
}
