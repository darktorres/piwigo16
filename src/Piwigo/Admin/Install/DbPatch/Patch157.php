<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Doctrine\DBAL\Connection;

/**
 * Former install/db/157-database.php (P23 sub-batch 8g-3).
 */
final class Patch157 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '157';
    }

    #[\Override]
    public function description(): string
    {
        return 'add config parameters to display smart app banner';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $configService = \Piwigo\Config\CurrentConfigService::get();
        $configService->confUpdateParam('show_mobile_app_banner_in_admin', true);
        $configService->confUpdateParam('show_mobile_app_banner_in_gallery', false);

        echo "\n" . $this->description() . "\n";
    }
}
