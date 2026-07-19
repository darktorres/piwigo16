<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\VersionUpgrade;

use Doctrine\DBAL\Connection;

/**
 * Former install/upgrade_2.2.0.php (P23 sub-batch 8g-4): marks ids <= 97
 * as not applied, runs patches 98-111, then chains to
 * UpgradeFrom_2_3_0.
 */
final class UpgradeFrom_2_2_0 extends AbstractRangeVersionUpgrade
{
    #[\Override]
    public function versionFrom(): string
    {
        return '2.2.0';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $this->markPreRangeNotApplied($conn, 97);
        $this->runPatchRange($conn, 98, 111);

        // now we upgrade from 2.3.0
        new UpgradeFrom_2_3_0()
            ->apply($conn);
    }
}
