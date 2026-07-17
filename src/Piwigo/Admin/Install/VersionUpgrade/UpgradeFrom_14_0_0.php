<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\VersionUpgrade;

/**
 * Former install/upgrade_14.0.0.php (P23 sub-batch 8g-4): marks ids <= 170
 * as not applied, runs patches 171-174, then chains to
 * UpgradeFrom_15_0_0.
 */
final class UpgradeFrom_14_0_0 extends AbstractRangeVersionUpgrade
{
    #[\Override]
    public function versionFrom(): string
    {
        return '14.0.0';
    }

    #[\Override]
    public function apply(): void
    {
        $this->markPreRangeNotApplied(170);
        $this->runPatchRange(171, 174);

        // now we upgrade from 15.0.0
        new UpgradeFrom_15_0_0()
            ->apply();
    }
}
