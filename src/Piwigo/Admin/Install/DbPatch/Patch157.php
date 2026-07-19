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
use Piwigo\Config\ConfigDb;

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
        ConfigDb::confUpdateParam('show_mobile_app_banner_in_admin', true, conn: $conn);
        ConfigDb::confUpdateParam('show_mobile_app_banner_in_gallery', false, conn: $conn);

        echo "\n" . $this->description() . "\n";
    }
}
