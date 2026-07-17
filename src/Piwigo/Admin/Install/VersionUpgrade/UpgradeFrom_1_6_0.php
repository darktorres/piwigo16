<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\VersionUpgrade;

use Piwigo\Db\MysqliDb;

/**
 * Former install/upgrade_1.6.0.php (P23 sub-batch 8g-4): upgrade from
 * 1.6.0/1.6.1 to 1.6.2-era schema, then chain to UpgradeFrom_1_6_2.
 */
final class UpgradeFrom_1_6_0 implements VersionUpgradeInterface
{
    #[\Override]
    public function versionFrom(): string
    {
        return '1.6.0';
    }

    #[\Override]
    public function apply(): void
    {
        /** @var string $prefixeTable */
        global $prefixeTable;

        $queries = [
            '
ALTER TABLE ' . $prefixeTable . 'user_infos
  ADD auto_login_key varchar(64) NOT NULL
;',
            '
ALTER TABLE ' . $prefixeTable . 'users
  CHANGE username username VARCHAR(100) binary NOT NULL
;',
        ];

        foreach ($queries as $query) {
            MysqliDb::query($query);
        }

        // now we upgrade from 1.6.2
        new UpgradeFrom_1_6_2()
            ->apply();
    }
}
