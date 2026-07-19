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
 * Former install/db/152-database.php (P23 sub-batch 8g-3).
 */
final class Patch152 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '152';
    }

    #[\Override]
    public function description(): string
    {
        return 'add 5 parameters to show/hide icons (edit/caddie/repressentative)';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        ConfigDb::confUpdateParam('index_edit_icon', 'true');
        ConfigDb::confUpdateParam('index_caddie_icon', 'true');
        ConfigDb::confUpdateParam('picture_edit_icon', 'true');
        ConfigDb::confUpdateParam('picture_caddie_icon', 'true');
        ConfigDb::confUpdateParam('picture_representative_icon', 'true');

        echo "\n" . $this->description() . "\n";
    }
}
