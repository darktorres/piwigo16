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
 * Former install/upgrade_2.4.0.php (P23 sub-batch 8g-4): marks ids <= 127
 * as not applied, runs patches 128-134, then chains to
 * UpgradeFrom_2_5_0.
 */
final class UpgradeFrom_2_4_0 extends AbstractRangeVersionUpgrade
{
    #[\Override]
    public function versionFrom(): string
    {
        return '2.4.0';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $this->markPreRangeNotApplied($conn, 127);
        $this->runPatchRange($conn, 128, 134);

        // now we upgrade from 2.5.0
        new UpgradeFrom_2_5_0()
            ->apply($conn);
    }
}
