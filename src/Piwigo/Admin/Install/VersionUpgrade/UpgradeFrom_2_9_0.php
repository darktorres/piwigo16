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
 * Former install/upgrade_2.9.0.php (P23 sub-batch 8g-4): marks ids <= 152
 * as not applied, runs patches 153-156, then chains to
 * UpgradeFrom_2_10_0.
 */
final class UpgradeFrom_2_9_0 extends AbstractRangeVersionUpgrade
{
    #[\Override]
    public function versionFrom(): string
    {
        return '2.9.0';
    }

    #[\Override]
    public function apply(): void
    {
        $this->markPreRangeNotApplied(152);
        $this->runPatchRange(153, 156);

        // now we upgrade from 2.10.0
        new UpgradeFrom_2_10_0()
            ->apply();
    }
}
