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
 * Former install/db/175-database.php (P23 sub-batch 8g-3).
 */
final class Patch175 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '175';
    }

    #[\Override]
    public function description(): string
    {
        return 'add config parameter to override Theme Login & Registration Pages';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        // Force use of standard pages on update
        \Piwigo\Config\CurrentConfigService::get()->confUpdateParam('use_standard_pages', true);

        echo "\n" . $this->description() . "\n";
    }
}
