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
 * Former install/upgrade_2.10.0.php (P23 sub-batch 8g-4): marks ids <= 156
 * as not applied, runs patches 157-159, then chains to
 * UpgradeFrom_11_0_0.
 */
final class UpgradeFrom_2_10_0 extends AbstractRangeVersionUpgrade
{
    #[\Override]
    public function versionFrom(): string
    {
        return '2.10.0';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $this->markPreRangeNotApplied($conn, 156);
        $this->runPatchRange($conn, 157, 159);

        // now we upgrade from 11.0.0
        new UpgradeFrom_11_0_0()
            ->apply($conn);
    }
}
