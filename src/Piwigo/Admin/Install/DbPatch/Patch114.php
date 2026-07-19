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
 * Former install/db/114-database.php (P23 sub-batch 8g-2).
 */
final class Patch114 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '114';
    }

    #[\Override]
    public function description(): string
    {
        return 'new parameter: Activate comments';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        ConfigDb::confUpdateParam('activate_comments', 'true');

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
