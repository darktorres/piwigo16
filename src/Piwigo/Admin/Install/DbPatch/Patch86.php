<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Piwigo\Admin\Install\InstallService;

/**
 * Former install/db/86-database.php (P23 sub-batch 8g-1). The bare
 * activate_core_themes() call became InstallService::activateCoreThemes()
 * (the same target its frozen-script delegate forwarded to).
 */
final class Patch86 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '86';
    }

    #[\Override]
    public function description(): string
    {
        return 'Automatically activate core themes.';
    }

    #[\Override]
    public function apply(): void
    {
        InstallService::activateCoreThemes();

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
