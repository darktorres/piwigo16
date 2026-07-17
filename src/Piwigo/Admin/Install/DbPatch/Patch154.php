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
 * Former install/db/154-database.php (P23 sub-batch 8g-3).
 */
final class Patch154 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '154';
    }

    #[\Override]
    public function description(): string
    {
        return 'add activity table';
    }

    #[\Override]
    public function apply(): void
    {
        MysqliDb::query('
CREATE TABLE `' . Tables::activity() . '` (
  `activity_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `object` varchar(255) NOT NULL,
  `object_id` int(11) unsigned NOT NULL,
  `action` varchar(255) NOT NULL,
  `performed_by` mediumint(8) unsigned NOT NULL,
  `occured_on` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `details` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`activity_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8
;');

        echo "\n" . $this->description() . "\n";
    }
}
