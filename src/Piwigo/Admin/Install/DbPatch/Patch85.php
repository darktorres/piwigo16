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
use Piwigo\Db\Tables;

/**
 * Former install/db/85-database.php (P23 sub-batch 8g-1). The bare
 * DB_CHARSET constant read became UpgradeCharset::dbCharset(), which
 * resolves the shell-defined constant first and Patch65's mid-run value
 * otherwise -- the same order the original relied on when a pre-2.0
 * database ran 65 and 85 in one continuous upgrade.
 */
final class Patch85 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '85';
    }

    #[\Override]
    public function description(): string
    {
        return 'Add a table to manage themes.';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $query = '
CREATE TABLE ' . Tables::themes() . " (
  `id` varchar(64) NOT NULL default '',
  `version` varchar(64) NOT NULL default '0',
  `name` varchar(64) default NULL,
  PRIMARY KEY  (`id`)
) ENGINE=MyISAM";

        if (UpgradeCharset::dbCharset() === 'utf8') {
            $query .= ' DEFAULT CHARACTER SET utf8';
        }

        $conn->executeStatement($query);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
