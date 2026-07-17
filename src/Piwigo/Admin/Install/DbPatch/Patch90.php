<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Piwigo\Admin\languages;
use Piwigo\Db\MysqliDb;
use Piwigo\Db\Tables;

/**
 * Former install/db/90-database.php (P23 sub-batch 8g-1). The bare
 * DB_CHARSET/PWG_CHARSET constant reads became UpgradeCharset accessors
 * (shell constant first, Patch65's mid-run value otherwise), same as
 * Patch85.
 */
final class Patch90 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '90';
    }

    #[\Override]
    public function description(): string
    {
        return 'Add a table to manage languages.';
    }

    #[\Override]
    public function apply(): void
    {
        $query = '
CREATE TABLE ' . Tables::languages() . " (
  `id` varchar(64) NOT NULL default '',
  `version` varchar(64) NOT NULL default '0',
  `name` varchar(64) default NULL,
  PRIMARY KEY  (`id`)
) ENGINE=MyISAM";

        if (UpgradeCharset::dbCharset() == 'utf8') {
            $query .= ' DEFAULT CHARACTER SET utf8';
        }

        MysqliDb::query($query);

        // Fill table

        $languages = new languages(UpgradeCharset::pwgCharset());

        foreach ($languages->fs_languages as $language_code => $fs_language) {
            $languages->perform_action('activate', $language_code);
        }

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
