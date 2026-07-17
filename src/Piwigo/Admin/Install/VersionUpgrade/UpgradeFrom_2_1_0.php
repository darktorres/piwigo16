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
 * Former install/upgrade_2.1.0.php (P23 sub-batch 8g-4): marks ids <= 90
 * as not applied, runs patches 91-97, then chains to
 * UpgradeFrom_2_2_0.
 */
final class UpgradeFrom_2_1_0 extends AbstractRangeVersionUpgrade
{
    #[\Override]
    public function versionFrom(): string
    {
        return '2.1.0';
    }

    #[\Override]
    public function apply(): void
    {
        $this->markPreRangeNotApplied(90);
        $this->runPatchRange(91, 97);

        // now we upgrade from 2.2.0
        new UpgradeFrom_2_2_0()
            ->apply();
    }
}
