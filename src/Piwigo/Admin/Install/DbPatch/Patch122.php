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
use Piwigo\Core\TimingHelper;
use Piwigo\Db\BatchWriter;
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
    public function apply(Connection $conn): void
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
        $starttime = TimingHelper::getMoment();

        $updates = [];

        foreach ($conn->fetchAllAssociative($query) as $row) {
            if (is_scalar($row['has_high']) && (string) $row['has_high'] === 'true') {
                $path = is_scalar($row['path']) ? (string) $row['path'] : '';
                $high_path = dirname($path) . '/pwg_high/' . basename($path);
                rename($high_path, $path);

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
            new BatchWriter($conn)
                ->massUpdate(
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
