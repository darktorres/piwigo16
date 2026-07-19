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
 * Former install/upgrade_2.8.0.php (P23 sub-batch 8g-4): marks ids <= 148
 * as not applied, runs patches 149-152, then chains to
 * UpgradeFrom_2_9_0.
 */
final class UpgradeFrom_2_8_0 extends AbstractRangeVersionUpgrade
{
    #[\Override]
    public function versionFrom(): string
    {
        return '2.8.0';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $this->markPreRangeNotApplied($conn, 148);
        $this->runPatchRange($conn, 149, 152);

        // now we upgrade from 2.9.0
        new UpgradeFrom_2_9_0()
            ->apply($conn);
    }
}
