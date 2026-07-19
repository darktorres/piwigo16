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
 * Former install/upgrade_12.0.0.php (P23 sub-batch 8g-4): marks ids <= 162
 * as not applied, runs patches 163-164, then chains to
 * UpgradeFrom_13_0_0.
 */
final class UpgradeFrom_12_0_0 extends AbstractRangeVersionUpgrade
{
    #[\Override]
    public function versionFrom(): string
    {
        return '12.0.0';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $this->markPreRangeNotApplied($conn, 162);
        $this->runPatchRange($conn, 163, 164);

        // now we upgrade from 13.0.0
        new UpgradeFrom_13_0_0()
            ->apply($conn);
    }
}
