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
    public function apply(): void
    {
        $query = '
INSERT INTO ' . Tables::config() . ' (param,value,comment)
  VALUES
    ("menubar_filter_icon","true","Display filter icon"),
    ("index_sort_order_input","true","Display image order selection list"),
    ("index_flat_icon","true","Display flat icon"),
    ("index_posted_date_icon","true","Display calendar by posted date"),
    ("index_created_date_icon","true","Display calendar by creation date icon"),
    ("index_slideshow_icon","true","Display slideshow icon"),
    ("picture_metadata_icon","true","Display metadata icon on picture page"),
    ("picture_slideshow_icon","true","Display slideshow icon on picture page"),
    ("picture_favorite_icon","true","Display favorite icon on picture page"),
    ("picture_navigation_icons","true","Display navigation icons on picture page"),
    ("picture_navigation_thumb","true","Display navigation thumbnails on picture page")
;';

        MysqliDb::query($query);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
