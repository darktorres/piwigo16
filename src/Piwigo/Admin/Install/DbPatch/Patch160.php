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
 * Former install/db/160-database.php (P23 sub-batch 8g-3).
 */
final class Patch160 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '160';
    }

    #[\Override]
    public function description(): string
    {
        return 'add lounge table';
    }

    #[\Override]
    public function apply(): void
    {
        MysqliDb::query('
CREATE TABLE `' . Tables::lounge() . '` (
  `image_id` mediumint(8) unsigned NOT NULL,
  `category_id` smallint(5) unsigned NOT NULL,
  PRIMARY KEY (`image_id`,`category_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8
;');

        echo "\n" . $this->description() . "\n";
    }
}
