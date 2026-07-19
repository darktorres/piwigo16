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
 * Former install/db/113-database.php (P23 sub-batch 8g-2).
 */
final class Patch113 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '113';
    }

    #[\Override]
    public function description(): string
    {
        return 'New settings for resizing original photo (related to multiple sizes feature)';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        ConfigDb::confUpdateParam('original_resize', 'false');
        ConfigDb::confUpdateParam('original_resize_maxwidth', 2016);
        ConfigDb::confUpdateParam('original_resize_maxheight', 2016);
        ConfigDb::confUpdateParam('original_resize_quality', 95);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
