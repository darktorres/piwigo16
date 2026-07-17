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
 * Former install/db/147-database.php (P23 sub-batch 8g-3).
 */
final class Patch147 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '147';
    }

    #[\Override]
    public function description(): string
    {
        return 'add user authentication keys table';
    }

    #[\Override]
    public function apply(): void
    {
        // we use PREFIX_TABLE, in case Piwigo uses an external user table
        MysqliDb::query('
CREATE TABLE `' . Tables::userAuthKeys() . '` (
  `auth_key_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `auth_key` varchar(255) NOT NULL,
  `user_id` mediumint(8) unsigned NOT NULL,
  `created_on` datetime NOT NULL,
  `duration` int(11) unsigned DEFAULT NULL,
  `expired_on` datetime NOT NULL,
  PRIMARY KEY (`auth_key_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8
;');

        echo "\n" . $this->description() . "\n";
    }
}
