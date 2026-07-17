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
 * Former install/upgrade_15.0.0.php (P23 sub-batch 8g-4): marks ids <= 174
 * as not applied, runs patches 175-181 -- the end of the version chain.
 */
final class UpgradeFrom_15_0_0 extends AbstractRangeVersionUpgrade
{
    #[\Override]
    public function versionFrom(): string
    {
        return '15.0.0';
    }

    #[\Override]
    public function apply(): void
    {
        $this->markPreRangeNotApplied(174);
        $this->runPatchRange(175, 181);
    }
}
