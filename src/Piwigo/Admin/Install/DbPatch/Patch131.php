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
 * Former install/db/131-database.php (P23 sub-batch 8g-2).
 */
final class Patch131 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '131';
    }

    #[\Override]
    public function description(): string
    {
        return 'add "nb_categories_page" parameter';
    }

    #[\Override]
    public function apply(): void
    {
        ConfigDb::confUpdateParam('nb_categories_page', '50');

        echo "\n" . $this->description() . "\n";
    }
}
