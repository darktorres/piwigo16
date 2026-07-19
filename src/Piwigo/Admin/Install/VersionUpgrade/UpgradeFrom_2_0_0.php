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
 * Former install/upgrade_2.0.0.php (P23 sub-batch 8g-4): marks ids <= 80
 * as not applied, runs patches 81-90, then chains to
 * UpgradeFrom_2_1_0.
 */
final class UpgradeFrom_2_0_0 extends AbstractRangeVersionUpgrade
{
    #[\Override]
    public function versionFrom(): string
    {
        return '2.0.0';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $this->markPreRangeNotApplied($conn, 80);
        $this->runPatchRange($conn, 81, 90);

        // now we upgrade from 2.1.0
        new UpgradeFrom_2_1_0()
            ->apply($conn);
    }
}
