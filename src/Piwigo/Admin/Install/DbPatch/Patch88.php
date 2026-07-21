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
 * Former install/db/88-database.php (P23 sub-batch 8g-1).
 */
final class Patch88 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '88';
    }

    #[\Override]
    public function description(): string
    {
        return 'Add display configuration for picture properties.';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $rows = [
            [
                'param' => 'picture_download_icon',
                'value' => 'true',
                'comment' => 'Display download icon on picture page',
            ],
            [
                'param' => 'picture_informations',
                'value' => 'a:11:{s:6:"author";b:1;s:10:"created_on";b:1;s:9:"posted_on";b:1;s:10:"dimensions";b:1;s:4:"file";b:1;s:8:"filesize";b:1;s:4:"tags";b:1;s:10:"categories";b:1;s:6:"visits";b:1;s:12:"average_rate";b:1;s:13:"privacy_level";b:1;}',
                'comment' => 'Information displayed on picture page',
            ],
        ];
        $batchWriter = new BatchWriter($conn);
        $batchWriter->massInsert(Tables::config(), ['param', 'value', 'comment'], $rows);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
