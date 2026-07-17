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
 * Former install/db/115-database.php (P23 sub-batch 8g-2).
 */
final class Patch115 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '115';
    }

    #[\Override]
    public function description(): string
    {
        return 'New setting for comments order on picture page';
    }

    #[\Override]
    public function apply(): void
    {
        ConfigDb::confUpdateParam('comments_order', 'ASC');

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
