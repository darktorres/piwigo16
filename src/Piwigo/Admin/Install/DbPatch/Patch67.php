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
 * Former install/db/67-database.php (P23 sub-batch 8g-1).
 */
final class Patch67 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '67';
    }

    #[\Override]
    public function description(): string
    {
        return 'Uninstall dew plugin';
    }

    #[\Override]
    public function apply(): void
    {
        $query = '
delete from ' . Tables::plugins() . " where id ='dew';
";
        MysqliDb::query($query);

        echo "\n"
        . '"' . $this->description() . '" ended'
        . "\n"
        ;
    }
}
