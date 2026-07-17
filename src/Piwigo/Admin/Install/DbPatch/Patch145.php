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
 * Former install/db/145-database.php (P23 sub-batch 8g-3).
 */
final class Patch145 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '145';
    }

    #[\Override]
    public function description(): string
    {
        return 'add image formats table';
    }

    #[\Override]
    public function apply(): void
    {
        // we use PREFIX_TABLE, in case Piwigo uses an external user table
        MysqliDb::query('
CREATE TABLE `' . Tables::imageFormat() . '` (
  `format_id` int(11) unsigned NOT NULL auto_increment,
  `image_id` mediumint(8) unsigned NOT NULL DEFAULT \'0\',
  `ext` varchar(255) NOT NULL,
  `filesize` mediumint(9) unsigned DEFAULT NULL,
  PRIMARY KEY  (`format_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8
;');

        echo "\n" . $this->description() . "\n";
    }
}
