<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Piwigo\Config\ConfigDb;

/**
 * Former install/db/174-database.php (P23 sub-batch 8g-3). The third
 * argument (updateGlobal=true) is original -- the fresh secret_key must
 * be visible in $conf for the remainder of the upgrade request.
 */
final class Patch174 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '174';
    }

    #[\Override]
    public function description(): string
    {
        return 'increase security on secret_key';
    }

    #[\Override]
    public function apply(): void
    {
        ConfigDb::confUpdateParam('secret_key', sha1(random_bytes(1000)), true);

        echo "\n" . $this->description() . "\n";
    }
}
