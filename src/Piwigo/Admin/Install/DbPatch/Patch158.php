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
 * Former install/db/158-database.php (P23 sub-batch 8g-3).
 */
final class Patch158 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '158';
    }

    #[\Override]
    public function description(): string
    {
        return 'set default date to 1970-01-01';
    }

    #[\Override]
    public function apply(): void
    {
        $queries = [
            'ALTER TABLE `' . Tables::comments() . '` CHANGE `date` `date` datetime NOT NULL default \'1970-01-01 00:00:00\';',
            'ALTER TABLE `' . Tables::history() . '` CHANGE `date` `date` date NOT NULL default \'1970-01-01\';',
            'ALTER TABLE `' . Tables::images() . '` CHANGE `date_available` `date_available` datetime NOT NULL default \'1970-01-01 00:00:00\';',
            'ALTER TABLE `' . Tables::oldPermalinks() . '` CHANGE  `date_deleted` `date_deleted` datetime NOT NULL default \'1970-01-01 00:00:00\';',
            'ALTER TABLE `' . Tables::rate() . '` CHANGE `date` `date` date NOT NULL default \'1970-01-01\';',
            'ALTER TABLE `' . Tables::sessions() . '` CHANGE `expiration` `expiration` datetime NOT NULL default \'1970-01-01 00:00:00\';',
            'ALTER TABLE `' . Tables::upgrade() . '` CHANGE `applied` `applied` datetime NOT NULL default \'1970-01-01 00:00:00\';',
            'ALTER TABLE `' . Tables::userInfos() . '` CHANGE `registration_date` `registration_date` datetime NOT NULL default \'1970-01-01 00:00:00\';',
        ];

        foreach ($queries as $query) {
            MysqliDb::query($query);
        }

        echo "\n" . $this->description() . "\n";
    }
}
