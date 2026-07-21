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
 * Former install/db/138-database.php (P23 sub-batch 8g-2).
 */
final class Patch138 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '138';
    }

    #[\Override]
    public function description(): string
    {
        return 'add "mail_theme" parameter';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        \Piwigo\Config\CurrentConfigService::get()->confUpdateParam('mail_theme', 'clear');

        echo "\n" . $this->description() . "\n";
    }
}
