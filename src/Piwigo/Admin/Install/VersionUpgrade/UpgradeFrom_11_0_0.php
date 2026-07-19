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
 * Former install/upgrade_11.0.0.php (P23 sub-batch 8g-4): marks ids <= 159
 * as not applied, runs patches 160-162, then chains to
 * UpgradeFrom_12_0_0.
 */
final class UpgradeFrom_11_0_0 extends AbstractRangeVersionUpgrade
{
    #[\Override]
    public function versionFrom(): string
    {
        return '11.0.0';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $this->markPreRangeNotApplied($conn, 159);
        $this->runPatchRange($conn, 160, 162);

        // now we upgrade from 12.0.0
        new UpgradeFrom_12_0_0()
            ->apply($conn);
    }
}
