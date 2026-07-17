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
 * Former install/upgrade_2.6.0.php (P23 sub-batch 8g-4): marks ids <= 139
 * as not applied, runs patches 140-144, then chains to
 * UpgradeFrom_2_7_0.
 */
final class UpgradeFrom_2_6_0 extends AbstractRangeVersionUpgrade
{
    #[\Override]
    public function versionFrom(): string
    {
        return '2.6.0';
    }

    #[\Override]
    public function apply(): void
    {
        $this->markPreRangeNotApplied(139);
        $this->runPatchRange(140, 144);

        // now we upgrade from 2.7.0
        new UpgradeFrom_2_7_0()
            ->apply();
    }
}
