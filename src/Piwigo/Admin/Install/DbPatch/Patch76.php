<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Piwigo\Db\MysqliDb;
use Piwigo\Db\Tables;

/**
 * Former install/db/76-database.php (P23 sub-batch 8g-1). Same
 * description-overwritten-with-query quirk as Patch75, preserved.
 */
final class Patch76 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '76';
    }

    #[\Override]
    public function description(): string
    {
        return 'ALTER TABLE ' . Tables::imageCategory() . ' add column `rank` mediumint(8) unsigned default NULL';
    }

    #[\Override]
    public function apply(): void
    {
        MysqliDb::query($this->description());

        echo "\n"
        . '"' . $this->description() . '" ended'
        . "\n"
        ;
    }
}
