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
 * Former install/db/151-database.php (P23 sub-batch 8g-3).
 */
final class Patch151 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '151';
    }

    #[\Override]
    public function description(): string
    {
        return 'add "picture_sizes_icon" and "index_sizes_icon" parameters';
    }

    #[\Override]
    public function apply(): void
    {
        ConfigDb::confUpdateParam('index_sizes_icon', 'true');
        ConfigDb::confUpdateParam('picture_sizes_icon', 'true');

        echo "\n" . $this->description() . "\n";
    }
}
