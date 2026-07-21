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
 * Former install/db/87-database.php (P23 sub-batch 8g-1).
 */
final class Patch87 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '87';
    }

    #[\Override]
    public function description(): string
    {
        return 'Add display configuration options.';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $rows = [
            [
                'param' => 'menubar_filter_icon',
                'value' => 'true',
                'comment' => 'Display filter icon',
            ],
            [
                'param' => 'index_sort_order_input',
                'value' => 'true',
                'comment' => 'Display image order selection list',
            ],
            [
                'param' => 'index_flat_icon',
                'value' => 'true',
                'comment' => 'Display flat icon',
            ],
            [
                'param' => 'index_posted_date_icon',
                'value' => 'true',
                'comment' => 'Display calendar by posted date',
            ],
            [
                'param' => 'index_created_date_icon',
                'value' => 'true',
                'comment' => 'Display calendar by creation date icon',
            ],
            [
                'param' => 'index_slideshow_icon',
                'value' => 'true',
                'comment' => 'Display slideshow icon',
            ],
            [
                'param' => 'picture_metadata_icon',
                'value' => 'true',
                'comment' => 'Display metadata icon on picture page',
            ],
            [
                'param' => 'picture_slideshow_icon',
                'value' => 'true',
                'comment' => 'Display slideshow icon on picture page',
            ],
            [
                'param' => 'picture_favorite_icon',
                'value' => 'true',
                'comment' => 'Display favorite icon on picture page',
            ],
            [
                'param' => 'picture_navigation_icons',
                'value' => 'true',
                'comment' => 'Display navigation icons on picture page',
            ],
            [
                'param' => 'picture_navigation_thumb',
                'value' => 'true',
                'comment' => 'Display navigation thumbnails on picture page',
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
