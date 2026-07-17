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
 * Former install/db/142-database.php (P23 sub-batch 8g-3).
 */
final class Patch142 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '142';
    }

    #[\Override]
    public function description(): string
    {
        return 'add "comments_enable_website" parameter';
    }

    #[\Override]
    public function apply(): void
    {
        ConfigDb::confUpdateParam('comments_enable_website', 'true');

        echo "\n" . $this->description() . "\n";
    }
}
