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
 * Former install/db/170-database.php (P23 sub-batch 8g-3).
 */
final class Patch170 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '170';
    }

    #[\Override]
    public function description(): string
    {
        return 'add config parameter to handle duplicate on upload';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        // we set it to false in this upgrade script, as opposed to the default value
        // for a new installation, because it was the default behavior before Piwigo 14
        \Piwigo\Config\CurrentConfigService::get()->confUpdateParam('upload_detect_duplicate', false);

        echo "\n" . $this->description() . "\n";
    }
}
